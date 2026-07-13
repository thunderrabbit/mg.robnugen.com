<?php
/**
 * Thrown by Issues\Issues when an issue does not exist or is not visible to the caller.
 *
 * REST layer (_issues.php) maps the equivalent condition to HTTP 404.
 * MCP server (mcp/index.php) maps this to JSON-RPC error -32603.
 *
 * @see \Issues\Issues
 */
namespace Issues;

class NotFoundException extends \Exception {}
