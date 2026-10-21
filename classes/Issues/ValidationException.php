<?php

/**
 * Thrown by Issues\IssueService when a request is well formed but asks for
 * something the data won't allow: an assignee who isn't a member, a parent in
 * another project, an unknown status, a repository outside the account.
 *
 * A field of the wrong shape is Input\InvalidInput instead, raised before the
 * service is reached; both map to the same answer.
 *
 * REST layer (_issues.php) maps the equivalent condition to HTTP 400.
 * MCP server (mcp/index.php) maps this to JSON-RPC error -32602.
 *
 * @see \Issues\IssueService
 */

declare(strict_types=1);

namespace Issues;

class ValidationException extends \Exception
{
}
