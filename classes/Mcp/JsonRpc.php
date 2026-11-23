<?php

/**
 * JSON-RPC 2.0 envelopes for the MCP endpoint (wwwroot/mcp/index.php).
 */

declare(strict_types=1);

namespace Mcp;

class JsonRpc
{
    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function ok(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * The answer to a tools/call: the data as JSON in one text block.
     *
     * @return array<string, mixed>
     */
    public static function toolResult(mixed $id, mixed $data): array
    {
        return self::ok($id, ['content' => [['type' => 'text', 'text' => json_encode($data)]]]);
    }
}
