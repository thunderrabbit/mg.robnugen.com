<?php

/**
 * JSON error replies for the Exterm REST API (wwwroot/api/v1/_exterm.php).
 *
 * Each method writes the reply and exits, so the route that called it stops.
 * The MCP server maps the same exceptions to JSON-RPC errors itself.
 *
 * @see \Exterm\Items
 */

declare(strict_types=1);

namespace Exterm;

class ApiErrors
{
    public static function send(int $code, string $msg): void
    {
        http_response_code($code);
        echo json_encode(['error' => $msg]);
        exit;
    }

    public static function fromException(AccessException|NotFoundException|ValidationException $e): void
    {
        if ($e instanceof NotFoundException) {
            self::send(404, $e->getMessage());
        }
        if ($e instanceof AccessException) {
            self::send(403, $e->getMessage());
        }
        if ($e instanceof ValidationException) {
            self::send(400, $e->getMessage());
        }
    }
}
