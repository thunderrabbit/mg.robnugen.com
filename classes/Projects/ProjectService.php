<?php

declare(strict_types=1);

namespace Projects;

use Db\Row\ProjectRow;
use Issues\AccessException;
use Issues\Caller;
use Issues\ConflictException;
use Issues\NotFoundException;
use Issues\ValidationException;
use Projects\Command\AddMember;
use Projects\Command\CreateProject;
use Projects\Command\UpdateMember;
use Projects\Command\UpdateProject;

/**
 * The project rules, in one place for the API and the web pages.
 *
 * Two kinds of right apply. Reading a project's content (the project itself,
 * its members, its issues) needs a project_members row with can_read; humans
 * need one too. Administering projects (creating, renaming, archiving and
 * deleting them, and managing their members) is for the account's human, who
 * may do it to any project in the account without being a member.
 *
 * It shares Caller, Membership and the exceptions with IssueService, so a page
 * or handler that uses both answers their errors the same way.
 */
final class ProjectService
{
    /**
     * @param (\Closure(string): void)|null $beforeWrite Runs once every check has passed,
     *        just before a write, with the action ('projects/create' and so on).
     *        The API charges a credit there, so a rejected request is never billed.
     */
    public function __construct(
        private ProjectStore $store,
        private ?\Closure $beforeWrite = null,
    ) {
    }

    public function humanCaller(int $userId): ?Caller
    {
        return $this->store->humanCaller($userId);
    }

    // ─── Reading ─────────────────────────────────────────────────────────────

    /**
     * @return list<ProjectView>
     */
    public function list(Caller $caller, bool $includeArchived = false): array
    {
        return $this->store->projectsFor($caller, $includeArchived);
    }

    /**
     * The caller's active projects with their open issue counts.
     *
     * @return list<ProjectSummary>
     */
    public function summaries(Caller $caller): array
    {
        return $this->store->summaries($caller);
    }

    public function get(Caller $caller, int $projectId): ProjectView
    {
        $role = $this->store->membership($projectId, $caller);
        $project = $this->store->project($projectId, $caller->userId);
        if ($role === null || !$role->canRead || $project === null) {
            throw new AccessException('No read access to this project');
        }
        return new ProjectView($project, $role);
    }

    /**
     * A project the caller may add issues to. Write access is enough; it doesn't
     * also need read access.
     */
    public function forWriting(Caller $caller, int $projectId): ProjectView
    {
        $role = $this->store->membership($projectId, $caller);
        $project = $this->store->project($projectId, $caller->userId);
        if ($role === null || $project === null) {
            throw new NotFoundException("Project {$projectId} not found or no access");
        }
        if (!$role->canWrite) {
            throw new AccessException('No write access to this project');
        }
        return new ProjectView($project, $role);
    }

    /**
     * @return list<MemberView>
     */
    public function members(Caller $caller, int $projectId): array
    {
        $this->get($caller, $projectId);
        return $this->store->members($projectId, $caller->userId);
    }

    /**
     * Who an issue in the project may be assigned to: its members, humans
     * first. Only someone who may write the project needs the list.
     *
     * @return list<Actor>
     */
    public function assigneeChoices(Caller $caller, int $projectId): array
    {
        $this->forWriting($caller, $projectId);
        return $this->store->assigneeChoices($projectId, $caller->userId);
    }

    /**
     * Open issues assigned to the caller, and the unassigned queue, across the
     * projects the caller can read.
     *
     * @return array{mine: list<DashboardIssue>, queue: list<DashboardIssue>}
     */
    public function dashboard(Caller $caller): array
    {
        return [
            'mine'  => $this->store->assignedTo($caller),
            'queue' => $this->store->unassigned($caller),
        ];
    }

    // ─── Administering projects ──────────────────────────────────────────────

    public function create(Caller $caller, CreateProject $new): ProjectRow
    {
        $this->requireHuman($caller, 'Only humans can create projects');
        if ($this->store->nameTaken($caller->userId, $new->name)) {
            throw new ConflictException("Project '{$new->name}' already exists");
        }
        $this->beforeWrite('projects/create');
        $projectId = $this->store->insertProject($new, $caller);
        return $this->store->project($projectId, $caller->userId)
            ?? throw new \LogicException("Project {$projectId} vanished after insert");
    }

    /**
     * Answers how many projects changed, which is 0 for one outside the account.
     */
    public function update(Caller $caller, UpdateProject $changes): int
    {
        $this->requireHuman($caller, 'Only humans can update projects');
        if (is_string($changes->name) && $this->store->nameTaken($caller->userId, $changes->name, $changes->projectId)) {
            throw new ConflictException("Project '{$changes->name}' already exists");
        }
        $this->beforeWrite('projects/update');
        return $this->store->updateProject($changes, $caller->userId);
    }

    /**
     * Deletes a project and, by cascade, everything in it.
     */
    public function delete(Caller $caller, int $projectId): int
    {
        $this->requireHuman($caller, 'Only humans can delete projects');
        $this->beforeWrite('projects/delete');
        return $this->store->deleteProject($projectId, $caller->userId);
    }

    /**
     * A project in the caller's account that the caller may administer.
     */
    public function forAdmin(Caller $caller, int $projectId): ProjectRow
    {
        $this->requireHuman($caller, 'Only humans can manage project members');
        return $this->store->project($projectId, $caller->userId)
            ?? throw new NotFoundException('Project not found');
    }

    // ─── Members ─────────────────────────────────────────────────────────────

    /**
     * The account's actors not yet in the project, most-used first. Those in
     * at least half the account's active projects are marked usual.
     *
     * @return list<Actor>
     */
    public function candidates(Caller $caller, int $projectId): array
    {
        $this->forAdmin($caller, $projectId);
        $total = $this->store->activeProjectCount($caller->userId);
        return array_map(
            static fn(Actor $actor): Actor => new Actor(
                aiu: $actor->aiu,
                name: $actor->name,
                actorType: $actor->actorType,
                memberships: $actor->memberships,
                usual: $total > 0 && $actor->memberships * 2 >= $total,
            ),
            $this->store->candidates($projectId, $caller->userId),
        );
    }

    public function addMember(Caller $caller, AddMember $member): void
    {
        $this->forAdmin($caller, $member->projectId);
        if (!$this->store->isAccountActor($member->memberAiu, $caller->userId)) {
            throw new ValidationException('member_aiu does not belong to this account');
        }
        if ($this->store->isMember($member->projectId, $member->memberAiu)) {
            throw new ConflictException('Agent is already a member of this project');
        }
        $this->beforeWrite('projects/add-member');
        $this->store->insertMember($member);
    }

    public function updateMember(Caller $caller, UpdateMember $changes): int
    {
        $this->requireHuman($caller, 'Only humans can manage project members');
        $this->beforeWrite('projects/update-member');
        return $this->store->updateMember($changes, $caller->userId);
    }

    public function removeMember(Caller $caller, int $projectId, int $memberAiu): int
    {
        $this->requireHuman($caller, 'Only humans can manage project members');
        $this->beforeWrite('projects/remove-member');
        return $this->store->deleteMember($projectId, $memberAiu, $caller->userId);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function requireHuman(Caller $caller, string $message): void
    {
        if (!$caller->isHuman) {
            throw new AccessException($message);
        }
    }

    private function beforeWrite(string $action): void
    {
        if ($this->beforeWrite !== null) {
            ($this->beforeWrite)($action);
        }
    }
}
