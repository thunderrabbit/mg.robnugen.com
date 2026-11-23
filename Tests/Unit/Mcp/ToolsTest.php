<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use Codeception\Test\Unit;
use Mcp\Tools;

/**
 * Hermetic unit tests for Mcp\Tools: which tools the MCP endpoint offers and
 * which of them only read. A tool that is not named as reading is gated on
 * can_write_project, so the list is pinned here.
 */
final class ToolsTest extends Unit
{
    private const array READS = [
        'exterm_list_projects',
        'exterm_list_items',
        'exterm_get_item',
        'exterm_search_items',
        'issue_list',
        'issue_get',
        'issue_comment_list',
    ];

    private const array WRITES = [
        'exterm_create_item',
        'exterm_update_item',
        'exterm_delete_item',
        'issue_create',
        'issue_update',
        'issue_comment_add',
    ];

    public function testTheReadingToolsReadOnly(): void
    {
        foreach (self::READS as $tool) {
            $this->assertTrue(Tools::readsOnly($tool), $tool);
        }
    }

    public function testEveryOtherToolCountsAsAWrite(): void
    {
        foreach (self::WRITES as $tool) {
            $this->assertFalse(Tools::readsOnly($tool), $tool);
        }
        $this->assertFalse(Tools::readsOnly('a_tool_added_later'));
    }

    public function testTheOfferedToolsAreExactlyTheReadsAndTheWrites(): void
    {
        $offered = array_column(Tools::defs(), 'name');
        $expected = [...self::READS, ...self::WRITES];
        sort($offered);
        sort($expected);

        $this->assertSame($expected, $offered);
    }

    public function testEveryToolHasADescriptionAndAnObjectSchema(): void
    {
        foreach (Tools::defs() as $def) {
            $this->assertIsString($def['description']);
            $this->assertNotSame('', $def['description']);
            $this->assertIsArray($def['inputSchema']);
            $this->assertSame('object', $def['inputSchema']['type']);
        }
    }
}
