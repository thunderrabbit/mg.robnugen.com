<?php
/**
 * Thrown by Issues\Issues when the caller lacks project membership or write access.
 *
 * REST layer (_issues.php) maps the equivalent condition to HTTP 403.
 * MCP server (mcp/index.php) maps this to JSON-RPC error -32603.
 *
 * @see \Issues\Issues
 */
namespace Issues;

class AccessException extends \Exception {}
