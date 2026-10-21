<?php

declare(strict_types=1);

namespace Tests\Unit\Projects;

use Db\Row\ProjectMemberRow;
use Db\Row\ProjectRow;
use Input\Absent;
use Issues\Caller;
use Issues\Membership;
use Projects\Actor;
use Projects\Command\AddMember;
use Projects\Command\CreateProject;
use Projects\Command\UpdateMember;
use Projects\Command\UpdateProject;
use Projects\MemberView;
use Projects\ProjectStore;
use Projects\ProjectSummary;
use Projects\ProjectView;

/**
 * ProjectStore in memory, for testing ProjectService's rules without MySQL.
 *
 * Names sort case-insensitively, as MySQL's collation does. The dashboard
 * queues are pure queries with no rules of their own, so the fake answers
 * them empty; the MySQL store owns that filtering.
 */
final class FakeProjectStore implements ProjectStore
{
    /** @var array<int, ProjectRow> */
    private array $projects = [];

    /** @var array<string, ProjectMemberRow> "project:aiu" => row */
    private array $members = [];

    /** @var array<int, array{userId: int, name: string, type: string}> */
    private array $actors = [];

    /** @var array<int, int> project_id => open issues */
    private array $openIssues = [];

    private int $nextProjectId = 1;

    private \DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new \DateTimeImmutable('2026-10-21 05:00:00', new \DateTimeZone('UTC'));
    }

    // ─── Fixtures ────────────────────────────────────────────────────────────

    public function addActor(int $aiu, int $userId, string $name, string $type = 'agent'): void
    {
        $this->actors[$aiu] = ['userId' => $userId, 'name' => $name, 'type' => $type];
    }

    public function addProject(int $projectId, int $userId, string $name, bool $archived = false): void
    {
        $this->projects[$projectId] = new ProjectRow(
            projectId: $projectId,
            userId: $userId,
            name: $name,
            description: null,
            isArchived: $archived,
            createdAtUtc: $this->now,
            updatedAtUtc: $this->now,
        );
        $this->nextProjectId = max($this->nextProjectId, $projectId + 1);
    }

    public function addMember(int $projectId, int $aiu, bool $canRead = true, bool $canWrite = true): void
    {
        $this->members["{$projectId}:{$aiu}"] = new ProjectMemberRow(
            projectId: $projectId,
            memberAiu: $aiu,
            canRead: $canRead,
            canWrite: $canWrite,
            grantedAtUtc: $this->now,
        );
    }

    public function setOpenIssues(int $projectId, int $count): void
    {
        $this->openIssues[$projectId] = $count;
    }

    // ─── ProjectStore ────────────────────────────────────────────────────────

    #[\Override]
    public function humanCaller(int $userId): ?Caller
    {
        foreach ($this->actors as $aiu => $actor) {
            if ($actor['userId'] === $userId && $actor['type'] === 'human') {
                return new Caller(userId: $userId, aiu: $aiu, isHuman: true);
            }
        }
        return null;
    }

    #[\Override]
    public function membership(int $projectId, Caller $caller): ?Membership
    {
        $project = $this->project($projectId, $caller->userId);
        $row = $this->members["{$projectId}:{$caller->aiu}"] ?? null;
        return $project === null || $row === null ? null : Membership::fromRow($row);
    }

    #[\Override]
    public function project(int $projectId, int $userId): ?ProjectRow
    {
        $project = $this->projects[$projectId] ?? null;
        return $project !== null && $project->userId === $userId ? $project : null;
    }

    #[\Override]
    public function projectsFor(Caller $caller, bool $includeArchived): array
    {
        $views = [];
        foreach ($this->byName() as $project) {
            $role = $this->membership($project->projectId, $caller);
            if ($role !== null && ($includeArchived || !$project->isArchived)) {
                $views[] = new ProjectView($project, $role);
            }
        }
        return $views;
    }

    #[\Override]
    public function summaries(Caller $caller): array
    {
        return array_map(
            fn(ProjectView $view): ProjectSummary => new ProjectSummary(
                $view->project,
                $this->openIssues[$view->project->projectId] ?? 0,
            ),
            $this->projectsFor($caller, false),
        );
    }

    #[\Override]
    public function nameTaken(int $userId, string $name, ?int $exceptProjectId = null): bool
    {
        foreach ($this->projects as $project) {
            if ($project->userId === $userId && $project->name === $name && $project->projectId !== $exceptProjectId) {
                return true;
            }
        }
        return false;
    }

    #[\Override]
    public function insertProject(CreateProject $project, Caller $creator): int
    {
        $id = $this->nextProjectId++;
        $this->projects[$id] = new ProjectRow(
            projectId: $id,
            userId: $creator->userId,
            name: $project->name,
            description: $project->description,
            isArchived: false,
            createdAtUtc: $this->now,
            updatedAtUtc: $this->now,
        );
        $this->addMember($id, $creator->aiu, canRead: true, canWrite: true);
        return $id;
    }

    #[\Override]
    public function updateProject(UpdateProject $changes, int $userId): int
    {
        $project = $this->project($changes->projectId, $userId);
        if ($project === null) {
            return 0;
        }
        $this->projects[$project->projectId] = new ProjectRow(
            projectId: $project->projectId,
            userId: $project->userId,
            name: $changes->name instanceof Absent ? $project->name : $changes->name,
            description: $changes->description instanceof Absent ? $project->description : $changes->description,
            isArchived: $changes->isArchived instanceof Absent ? $project->isArchived : $changes->isArchived,
            createdAtUtc: $project->createdAtUtc,
            updatedAtUtc: $this->now,
        );
        return 1;
    }

    #[\Override]
    public function deleteProject(int $projectId, int $userId): int
    {
        if ($this->project($projectId, $userId) === null) {
            return 0;
        }
        unset($this->projects[$projectId]);
        foreach ($this->members as $key => $member) {
            if ($member->projectId === $projectId) {
                unset($this->members[$key]);
            }
        }
        return 1;
    }

    #[\Override]
    public function members(int $projectId, int $userId): array
    {
        if ($this->project($projectId, $userId) === null) {
            return [];
        }
        $views = [];
        foreach ($this->members as $member) {
            if ($member->projectId === $projectId) {
                $actor = $this->actors[$member->memberAiu];
                $views[] = new MemberView($member, $actor['name'], null, $actor['type']);
            }
        }
        usort($views, static fn(MemberView $a, MemberView $b): int => strcasecmp($a->name, $b->name));
        return $views;
    }

    #[\Override]
    public function assigneeChoices(int $projectId, int $userId): array
    {
        $choices = array_map(
            static fn(MemberView $m): Actor => new Actor($m->member->memberAiu, $m->name, $m->actorType),
            $this->members($projectId, $userId),
        );
        usort(
            $choices,
            static fn(Actor $a, Actor $b): int => strcmp($b->actorType, $a->actorType) ?: strcasecmp($a->name, $b->name),
        );
        return $choices;
    }

    #[\Override]
    public function candidates(int $projectId, int $userId): array
    {
        $candidates = [];
        foreach ($this->actors as $aiu => $actor) {
            if ($actor['userId'] !== $userId || $this->isMember($projectId, $aiu)) {
                continue;
            }
            $memberships = count(array_filter(
                $this->members,
                static fn(ProjectMemberRow $m): bool => $m->memberAiu === $aiu,
            ));
            $candidates[] = new Actor($aiu, $actor['name'], $actor['type'], $memberships);
        }
        usort(
            $candidates,
            static fn(Actor $a, Actor $b): int => ($b->memberships <=> $a->memberships) ?: strcasecmp($a->name, $b->name),
        );
        return $candidates;
    }

    #[\Override]
    public function activeProjectCount(int $userId): int
    {
        return count(array_filter(
            $this->projects,
            static fn(ProjectRow $p): bool => $p->userId === $userId && !$p->isArchived,
        ));
    }

    #[\Override]
    public function isAccountActor(int $aiu, int $userId): bool
    {
        return ($this->actors[$aiu]['userId'] ?? null) === $userId;
    }

    #[\Override]
    public function isMember(int $projectId, int $aiu): bool
    {
        return isset($this->members["{$projectId}:{$aiu}"]);
    }

    #[\Override]
    public function insertMember(AddMember $member): void
    {
        $this->addMember($member->projectId, $member->memberAiu, $member->canRead, $member->canWrite);
    }

    #[\Override]
    public function updateMember(UpdateMember $changes, int $userId): int
    {
        $key = "{$changes->projectId}:{$changes->memberAiu}";
        $old = $this->members[$key] ?? null;
        if ($old === null || $this->project($changes->projectId, $userId) === null) {
            return 0;
        }
        $this->addMember(
            $changes->projectId,
            $changes->memberAiu,
            canRead: $changes->canRead instanceof Absent ? $old->canRead : $changes->canRead,
            canWrite: $changes->canWrite instanceof Absent ? $old->canWrite : $changes->canWrite,
        );
        return 1;
    }

    #[\Override]
    public function deleteMember(int $projectId, int $aiu, int $userId): int
    {
        $key = "{$projectId}:{$aiu}";
        if (!isset($this->members[$key]) || $this->project($projectId, $userId) === null) {
            return 0;
        }
        unset($this->members[$key]);
        return 1;
    }

    #[\Override]
    public function assignedTo(Caller $caller): array
    {
        return [];
    }

    #[\Override]
    public function unassigned(Caller $caller): array
    {
        return [];
    }

    /**
     * @return list<ProjectRow>
     */
    private function byName(): array
    {
        $projects = array_values($this->projects);
        usort($projects, static fn(ProjectRow $a, ProjectRow $b): int => strcasecmp($a->name, $b->name));
        return $projects;
    }
}
