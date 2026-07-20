<?php

/**
 * Thrown by Issues\Issues for invalid input (empty title, bad priority, etc.).
 *
 * REST layer (_issues.php) maps the equivalent condition to HTTP 400.
 * MCP server (mcp/index.php) maps this to JSON-RPC error -32602.
 *
 * @see \Issues\Issues
 */

namespace Issues;

class ValidationException extends \Exception
{
}
