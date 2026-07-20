<?php

/**
 * Thrown by Exterm\Items when the caller lacks project membership or write
 * permission.
 *
 * REST API (_exterm.php) maps this to HTTP 403.
 * MCP server (mcp/index.php) maps this to JSON-RPC error -32603.
 *
 * @see \Exterm\Items
 */

namespace Exterm;

class AccessException extends \RuntimeException
{
}
