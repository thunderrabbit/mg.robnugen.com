<?php

/**
 * Thrown by Exterm\Items when caller-supplied input is invalid — empty title,
 * unknown status value, attempted direct jump to 'done' on an irreversible
 * task, etc.
 *
 * REST API (_exterm.php) maps this to HTTP 400.
 * MCP server (mcp/index.php) maps this to JSON-RPC error -32602.
 *
 * @see \Exterm\Items
 */

namespace Exterm;

class ValidationException extends \RuntimeException
{
}
