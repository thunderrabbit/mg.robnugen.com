<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use Codeception\Test\Unit;
use Mcp\JsonRpc;

/**
 * Hermetic unit tests for Mcp\JsonRpc, the JSON-RPC 2.0 envelopes the MCP
 * endpoint answers with.
 */
final class JsonRpcTest extends Unit
{
    public function testOkWrapsTheResultWithTheRequestId(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['tools' => []]],
            JsonRpc::ok(7, ['tools' => []]),
        );
    }

    public function testOkKeepsTheIdAsItCame(): void
    {
        $this->assertSame('abc', JsonRpc::ok('abc', [])['id']);
        $this->assertNull(JsonRpc::ok(null, [])['id']);
    }

    public function testErrorCarriesCodeAndMessage(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -32601, 'message' => 'Method not found']],
            JsonRpc::error(7, -32601, 'Method not found'),
        );
    }

    public function testToolResultIsTheDataAsJsonInOneTextBlock(): void
    {
        $this->assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 7,
                'result' => ['content' => [['type' => 'text', 'text' => '{"issue_id":3,"tags":["a","b"]}']]],
            ],
            JsonRpc::toolResult(7, ['issue_id' => 3, 'tags' => ['a', 'b']]),
        );
    }
}
