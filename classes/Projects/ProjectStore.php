<?php

declare(strict_types=1);

namespace Projects;

use Db\Row\ProjectRow;
use Issues\Caller;
use Issues\Membership;
use Projects\Command\AddMember;
use Projects\Command\CreateProject;
use Projects\Command\UpdateMember;
use Projects\Command\UpdateProject;

/**
 * The persistence ProjectService needs, and nothing more. PdoProjectStore talks
 * to MySQL; the unit tests use an in-memory fake. Rules live in ProjectService,
 * so a store only reads and writes.
 *
 * Every read and write is scoped to an account (a user id), so no store call
 * reaches a project in someone else's account.
 */
interface ProjectStore
{
    /**
     * The human actor of an account, as the web pages act through, or null when
     * the account has none.
     */
    public function humanCaller(int $userId): ?Caller;

    /**
     * The caller's membership on a project in the caller's account, or null.
     */
    public function membership(int $projectId, Caller $caller): ?Membership;

    public function project(int $projectId, int $userId): ?ProjectRow;

    /**
     * The projects the caller is a member of, by name.
     *
     * @return list<ProjectView>
     */
    public function projectsFor(Caller $caller, bool $includeArchived): array;

    /**
     * The caller's active projects with their open issue counts, by name.
     *
     * @return list<ProjectSummary>
     */
    public function summaries(Caller $caller): array;

    public function nameTaken(int $userId, string $name, ?int $exceptProjectId = null): bool;

    /**
     * Inserts a project and makes its creator a reading and writing member, in
     * one transaction, and returns the new id.
     */
    public function insertProject(CreateProject $project, Caller $creator): int;

    /**
     * Applies the sent fields, and answers how many rows changed.
     */
    public function updateProject(UpdateProject $changes, int $userId): int;

    public function deleteProject(int $projectId, int $userId): int;

    /**
     * A project's members, by name.
     *
     * @return list<MemberView>
     */
    public function members(int $projectId, int $userId): array;

    /**
     * A project's members as assignee choices: humans first, then by name.
     *
     * @return list<Actor>
     */
    public function assigneeChoices(int $projectId, int $userId): array;

    /**
     * The account's actors not yet in a project, most-used first.
     *
     * @return list<Actor>
     */
    public function candidates(int $projectId, int $userId): array;

    public function activeProjectCount(int $userId): int;

    public function isAccountActor(int $aiu, int $userId): bool;

    public function isMember(int $projectId, int $aiu): bool;

    public function insertMember(AddMember $member): void;

    public function updateMember(UpdateMember $changes, int $userId): int;

    public function deleteMember(int $projectId, int $aiu, int $userId): int;

    /**
     * Open issues assigned to the caller in projects they can read: high
     * priority first, then most recently updated.
     *
     * @return list<DashboardIssue>
     */
    public function assignedTo(Caller $caller): array;

    /**
     * Open unassigned issues in projects the caller can read: high priority
     * first, then oldest first.
     *
     * @return list<DashboardIssue>
     */
    public function unassigned(Caller $caller): array;
}
