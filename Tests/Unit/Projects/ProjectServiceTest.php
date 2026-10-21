<?php

declare(strict_types=1);

namespace Tests\Unit\Projects;

use Codeception\Test\Unit;
use Issues\AccessException;
use Issues\Caller;
use Issues\ConflictException;
use Issues\NotFoundException;
use Issues\ValidationException;
use Projects\Actor;
use Projects\Command\AddMember;
use Projects\Command\CreateProject;
use Projects\Command\UpdateMember;
use Projects\Command\UpdateProject;
use Projects\MemberView;
use Projects\ProjectService;
use Projects\ProjectView;

/**
 * ProjectService's rules against an in-memory store: account 1 (Rob, his
 * agents, projects 7 to 9) and account 2 next door with project 20.
 */
final class ProjectServiceTest extends Unit
{
    private FakeProjectStore $store;
    private ProjectService $service;

    /** @var list<string> */
    private array $charged = [];

    private Caller $rob;
    private Caller $writer;
    private Caller $reader;
    private Caller $blocked;
    private Caller $neighbour;

    protected function _before(): void
    {
        $this->store = new FakeProjectStore();
        $this->store->addActor(1, userId: 1, name: 'Rob', type: 'human');
        $this->store->addActor(35, userId: 1, name: 'mgClaude');
        $this->store->addActor(36, userId: 1, name: 'Boss Claude');
        $this->store->addActor(37, userId: 1, name: 'eliClaude');
        $this->store->addActor(38, userId: 1, name: 'test_bot');
        $this->store->addActor(90, userId: 2, name: 'Neighbour', type: 'human');

        $this->store->addProject(7, userId: 1, name: 'jikan');
        $this->store->addProject(8, userId: 1, name: 'abc');
        $this->store->addProject(9, userId: 1, name: 'old', archived: true);
        $this->store->addProject(20, userId: 2, name: 'next door');

        $this->store->addMember(7, 1);
        $this->store->addMember(7, 35);
        $this->store->addMember(7, 36, canWrite: false);
        $this->store->addMember(7, 37, canRead: false, canWrite: true);
        $this->store->addMember(8, 1);
        $this->store->addMember(8, 35);
        $this->store->addMember(9, 1);
        $this->store->addMember(20, 90);

        $this->rob = new Caller(userId: 1, aiu: 1, isHuman: true);
        $this->writer = new Caller(userId: 1, aiu: 35, isHuman: false);
        $this->reader = new Caller(userId: 1, aiu: 36, isHuman: false);
        $this->blocked = new Caller(userId: 1, aiu: 37, isHuman: false);
        $this->neighbour = new Caller(userId: 2, aiu: 90, isHuman: true);

        $this->charged = [];
        $this->service = new ProjectService(
            $this->store,
            beforeWrite: function (string $action): void {
                $this->charged[] = $action;
            },
        );
    }

    // === Reading ===

    public function testListShowsTheCallersProjectsByNameAndHidesArchivedOnes(): void
    {
        $this->assertSame(['abc', 'jikan'], $this->names($this->service->list($this->rob)));
        $this->assertSame(['abc', 'jikan', 'old'], $this->names($this->service->list($this->rob, includeArchived: true)));
        $this->assertSame(['jikan'], $this->names($this->service->list($this->reader)));
        $this->assertSame(['next door'], $this->names($this->service->list($this->neighbour)));
    }

    public function testGetNeedsReadAccessInTheCallersAccount(): void
    {
        $view = $this->service->get($this->reader, 7);

        $this->assertSame('jikan', $view->project->name);
        $this->assertTrue($view->membership->canRead);
        $this->assertFalse($view->membership->canWrite);
        $this->assertSame(
            ['project_id', 'name', 'description', 'is_archived', 'created_at_utc', 'updated_at_utc', 'my_can_read', 'my_can_write'],
            array_keys($view->jsonSerialize()),
        );

        $this->assertThrows(AccessException::class, fn() => $this->service->get($this->blocked, 7));
        $this->assertThrows(AccessException::class, fn() => $this->service->get($this->reader, 8));
        $this->assertThrows(AccessException::class, fn() => $this->service->get($this->neighbour, 7));
    }

    public function testForWritingNeedsWriteAccessButNotReadAccess(): void
    {
        $this->assertSame('jikan', $this->service->forWriting($this->blocked, 7)->project->name);
        $this->assertThrows(AccessException::class, fn() => $this->service->forWriting($this->reader, 7));
        $this->assertThrows(NotFoundException::class, fn() => $this->service->forWriting($this->reader, 8));
        $this->assertThrows(NotFoundException::class, fn() => $this->service->forWriting($this->neighbour, 7));
    }

    public function testMembersAreListedByNameForReaders(): void
    {
        $names = array_map(static fn(MemberView $m): string => $m->name, $this->service->members($this->reader, 7));

        $this->assertSame(['Boss Claude', 'eliClaude', 'mgClaude', 'Rob'], $names);
        $this->assertThrows(AccessException::class, fn() => $this->service->members($this->blocked, 7));
    }

    public function testAssigneeChoicesPutHumansFirstAndNeedWriteAccess(): void
    {
        $names = array_map(static fn(Actor $a): string => $a->name, $this->service->assigneeChoices($this->writer, 7));

        $this->assertSame(['Rob', 'Boss Claude', 'eliClaude', 'mgClaude'], $names);
        $this->assertThrows(AccessException::class, fn() => $this->service->assigneeChoices($this->reader, 7));
    }

    public function testSummariesCarryOpenIssueCounts(): void
    {
        $this->store->setOpenIssues(7, 4);

        $summaries = $this->service->summaries($this->rob);

        $this->assertSame(['abc', 'jikan'], array_map(static fn($s) => $s->project->name, $summaries));
        $this->assertSame([0, 4], array_map(static fn($s) => $s->openIssueCount, $summaries));
    }

    // === Administering projects ===

    public function testOnlyAHumanMayCreateAndTheCreatorJoinsWithFullRights(): void
    {
        $project = $this->service->create($this->rob, CreateProject::fromInput(['name' => 'mg', 'description' => 'Tracker']));

        $this->assertSame('mg', $project->name);
        $this->assertSame('Tracker', $project->description);
        $this->assertSame(['projects/create'], $this->charged);
        $role = $this->service->get($this->rob, $project->projectId)->membership;
        $this->assertTrue($role->canRead && $role->canWrite);

        $this->assertThrows(AccessException::class, fn() => $this->service->create($this->writer, new CreateProject('x')));
        $this->assertSame(['projects/create'], $this->charged);
    }

    public function testANameIsUniqueWithinAnAccountOnly(): void
    {
        $this->assertThrows(ConflictException::class, fn() => $this->service->create($this->rob, new CreateProject('jikan')));
        $this->assertSame([], $this->charged);

        $this->assertSame('jikan', $this->service->create($this->neighbour, new CreateProject('jikan'))->name);
    }

    public function testUpdateRenamesAndArchivesButNotOntoATakenName(): void
    {
        $this->assertSame(1, $this->service->update($this->rob, new UpdateProject(8, name: 'abc2', isArchived: true)));
        $this->assertSame(['jikan'], array_map(static fn($v) => $v->project->name, $this->service->list($this->rob)));

        $this->assertThrows(ConflictException::class, fn() => $this->service->update($this->rob, new UpdateProject(8, name: 'jikan')));
        $this->assertSame(1, $this->service->update($this->rob, new UpdateProject(7, name: 'jikan')));
        $this->assertThrows(AccessException::class, fn() => $this->service->update($this->writer, new UpdateProject(7, isArchived: true)));
        $this->assertSame(0, $this->service->update($this->rob, new UpdateProject(20, isArchived: true)));
    }

    public function testDeleteIsForTheAccountsHumanOnly(): void
    {
        $this->assertThrows(AccessException::class, fn() => $this->service->delete($this->writer, 8));
        $this->assertSame(0, $this->service->delete($this->rob, 20));
        $this->assertSame(1, $this->service->delete($this->rob, 8));
        $this->assertSame(['projects/delete', 'projects/delete'], $this->charged);
    }

    // === Members ===

    public function testAddingAMemberChecksTheProjectTheActorAndDuplicates(): void
    {
        $this->service->addMember($this->rob, new AddMember(8, 36));
        $this->assertTrue($this->store->isMember(8, 36));
        $this->assertSame(['projects/add-member'], $this->charged);

        $this->assertThrows(ConflictException::class, fn() => $this->service->addMember($this->rob, new AddMember(8, 36)));
        $this->assertThrows(ValidationException::class, fn() => $this->service->addMember($this->rob, new AddMember(8, 90)));
        $this->assertThrows(NotFoundException::class, fn() => $this->service->addMember($this->rob, new AddMember(20, 36)));
        $this->assertThrows(AccessException::class, fn() => $this->service->addMember($this->writer, new AddMember(8, 36)));
        $this->assertSame(['projects/add-member'], $this->charged);
    }

    public function testCandidatesAreTheAccountsOtherActorsWithTheUsualOnesMarked(): void
    {
        // Two active projects (7 and 8), so an actor in at least one is usual.
        $this->assertSame(
            ['Boss Claude' => true, 'eliClaude' => true, 'test_bot' => false],
            $this->usual($this->service->candidates($this->rob, 8)),
        );
        $this->assertSame(
            ['mgClaude' => true, 'Boss Claude' => true, 'eliClaude' => true, 'test_bot' => false],
            $this->usual($this->service->candidates($this->rob, 9)),
        );
        $this->assertThrows(NotFoundException::class, fn() => $this->service->candidates($this->rob, 20));
        $this->assertThrows(AccessException::class, fn() => $this->service->candidates($this->writer, 8));
    }

    public function testMemberRightsChangeAndMembersLeave(): void
    {
        $this->assertSame(1, $this->service->updateMember($this->rob, new UpdateMember(7, 36, canWrite: true)));
        $this->assertTrue($this->service->forWriting($this->reader, 7)->membership->canWrite);

        $this->assertSame(1, $this->service->removeMember($this->rob, 7, 36));
        $this->assertThrows(AccessException::class, fn() => $this->service->get($this->reader, 7));

        $this->assertSame(0, $this->service->removeMember($this->rob, 20, 90));
        $this->assertThrows(AccessException::class, fn() => $this->service->removeMember($this->writer, 7, 35));
        $this->assertSame(['projects/update-member', 'projects/remove-member', 'projects/remove-member'], $this->charged);
    }

    /**
     * @param list<ProjectView> $views
     * @return list<string>
     */
    private function names(array $views): array
    {
        return array_map(static fn(ProjectView $v): string => $v->project->name, $views);
    }

    /**
     * @param list<Actor> $actors
     * @return array<string, bool>
     */
    private function usual(array $actors): array
    {
        $usual = [];
        foreach ($actors as $actor) {
            $usual[$actor->name] = $actor->usual;
        }
        return $usual;
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function assertThrows(string $class, \Closure $action): void
    {
        try {
            $action();
            $this->fail("Expected {$class}");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
        }
    }
}
