<?php

/**
 * Thrown by Issues\IssueService when the caller lacks project membership or
 * write access, or when what they are changing belongs to another agent.
 *
 * REST layer (_issues.php) maps the equivalent condition to HTTP 403.
 * MCP server (mcp/index.php) maps this to JSON-RPC error -32603.
 *
 * @see \Issues\IssueService
 */

namespace Issues;

class AccessException extends \Exception
{
}
