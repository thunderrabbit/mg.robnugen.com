<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Codeception\Test\Unit;
use Db\Enum\IssuePriority;
use Input\Absent;
use Input\InvalidInput;
use Issues\Command\CreateIssue;
use Issues\Command\UpdateIssue;

/**
 * The typed commands every surface builds from its request: a JSON body, a
 * form post or MCP tool arguments.
 */
final class IssueCommandsTest extends Unit
{
    public function testCreateIssueFillsDefaults(): void
    {
        $create = CreateIssue::fromInput(['project_id' => '7', 'title' => ' Typed issues ']);

        $this->assertSame(7, $create->projectId);
        $this->assertSame('Typed issues', $create->title);
        $this->assertNull($create->description);
        $this->assertSame(IssuePriority::Normal, $create->priority);
        $this->assertNull($create->assigneeAiu);
        $this->assertNull($create->parentIssueId);
        $this->assertNull($create->statusId);
    }

    public function testCreateIssueRejectsBadFields(): void
    {
        $this->assertInvalid('project_id is required', static fn() => CreateIssue::fromInput(['title' => 'x']));
        $this->assertInvalid('title is required', static fn() => CreateIssue::fromInput(['project_id' => 7]));
        $this->assertInvalid(
            "priority must be 'low', 'normal', or 'high'",
            static fn() => CreateIssue::fromInput(['project_id' => 7, 'title' => 'x', 'priority' => 'urgent']),
        );
    }

    public function testUpdateIssueLeavesUnsentFieldsAbsent(): void
    {
        $update = UpdateIssue::fromInput(['issue_id' => 5, 'description' => '', 'assignee_aiu' => 0]);

        $this->assertSame(5, $update->issueId);
        $this->assertSame(Absent::Field, $update->title);
        $this->assertSame(Absent::Field, $update->priority);
        $this->assertSame(Absent::Field, $update->statusId);
        $this->assertSame(Absent::Field, $update->parentIssueId);
        $this->assertNull($update->description);
        $this->assertNull($update->assigneeAiu);
    }

    public function testUpdateIssueNeedsSomethingToChange(): void
    {
        $this->assertInvalid(
            'provide at least one field to update',
            static fn() => UpdateIssue::fromInput(['issue_id' => 5]),
        );
        $this->assertInvalid(
            'title is required',
            static fn() => UpdateIssue::fromInput(['issue_id' => 5, 'title' => '  ']),
        );
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
