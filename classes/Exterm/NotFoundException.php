<?php

/**
 * Thrown by Exterm\Items when the requested item does not exist or is not
 * visible to the caller (wrong project owner, not a project member, etc.).
 *
 * REST API (_exterm.php) maps this to HTTP 404.
 * MCP server (mcp/index.php) maps this to JSON-RPC error -32603.
 *
 * @see \Exterm\Items
 */

namespace Exterm;

class NotFoundException extends \RuntimeException
{
}
