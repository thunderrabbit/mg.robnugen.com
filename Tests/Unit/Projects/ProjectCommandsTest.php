<?php

declare(strict_types=1);

namespace Tests\Unit\Projects;

use Codeception\Test\Unit;
use Input\Absent;
use Input\InvalidInput;
use Projects\Command\AddMember;
use Projects\Command\CreateProject;
use Projects\Command\UpdateMember;
use Projects\Command\UpdateProject;

/**
 * The typed commands the project API and pages build from their requests.
 */
final class ProjectCommandsTest extends Unit
{
    public function testCreateProjectTrimsAndTreatsAnEmptyDescriptionAsNone(): void
    {
        $create = CreateProject::fromInput(['name' => ' jikan ', 'description' => '  ']);

        $this->assertSame('jikan', $create->name);
        $this->assertNull($create->description);
        $this->assertSame('Agents', CreateProject::fromInput(['name' => 'x', 'description' => ' Agents '])->description);
    }

    public function testCreateProjectRejectsBadNames(): void
    {
        $this->assertInvalid('name is required', static fn() => CreateProject::fromInput(['name' => ' ']));
        $this->assertInvalid(
            'name must be 100 characters or fewer',
            static fn() => CreateProject::fromInput(['name' => str_repeat('a', 101)]),
        );
        $this->assertInvalid('name must be a string', static fn() => CreateProject::fromInput(['name' => 7]));
    }

    public function testUpdateProjectChangesOnlyWhatWasSent(): void
    {
        $update = UpdateProject::fromInput(['project_id' => 3, 'is_archived' => true]);

        $this->assertSame(3, $update->projectId);
        $this->assertSame(Absent::Field, $update->name);
        $this->assertSame(Absent::Field, $update->description);
        $this->assertTrue($update->isArchived);

        $cleared = UpdateProject::fromInput(['project_id' => 3, 'description' => '']);
        $this->assertNull($cleared->description);
    }

    public function testUpdateProjectNeedsSomethingToChange(): void
    {
        $this->assertInvalid(
            'provide name, description, and/or is_archived to update',
            static fn() => UpdateProject::fromInput(['project_id' => 3]),
        );
        $this->assertInvalid('name is required', static fn() => UpdateProject::fromInput(['project_id' => 3, 'name' => '']));
    }

    public function testAddMemberDefaultsToReadOnly(): void
    {
        $add = AddMember::fromInput(['project_id' => 3, 'member_aiu' => 35]);

        $this->assertTrue($add->canRead);
        $this->assertFalse($add->canWrite);

        $writer = AddMember::fromInput(['project_id' => 3, 'member_aiu' => 35, 'can_read' => 0, 'can_write' => 1]);
        $this->assertFalse($writer->canRead);
        $this->assertTrue($writer->canWrite);
    }

    public function testUpdateMemberNeedsARightToChange(): void
    {
        $update = UpdateMember::fromInput(['project_id' => 3, 'member_aiu' => 35, 'can_write' => false]);
        $this->assertSame(Absent::Field, $update->canRead);
        $this->assertFalse($update->canWrite);

        $this->assertInvalid(
            'provide can_read and/or can_write to update',
            static fn() => UpdateMember::fromInput(['project_id' => 3, 'member_aiu' => 35]),
        );
        $this->assertInvalid('member_aiu is required', static fn() => UpdateMember::fromInput(['project_id' => 3]));
    }

    private function assertInvalid(string $message, \Closure $build): void
    {
        try {
            $build();
            $this->fail("Expected InvalidInput: {$message}");
        } catch (InvalidInput $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
