<?php

/**
 * Thrown by Issues\IssueService when an issue, comment, timer or commit link
 * does not exist or is not visible to the caller.
 *
 * REST layer (_issues.php) maps the equivalent condition to HTTP 404.
 * MCP server (mcp/index.php) maps this to JSON-RPC error -32603.
 *
 * @see \Issues\IssueService
 */

namespace Issues;

class NotFoundException extends \Exception
{
}
