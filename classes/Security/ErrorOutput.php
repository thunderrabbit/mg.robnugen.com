<?php

declare(strict_types=1);

namespace Security;

/**
 * Where PHP's errors and uncaught exceptions go: the log, unless a site asks
 * to see them.
 *
 * hide() is the first thing prepend.php does, before the config is read, so
 * nothing is ever on screen by accident; login/register.php and
 * billing/webhook.php, which run without prepend.php, call it straight after
 * their autoloader. show() undoes it for a server whose site_config.php sets
 * display_errors to true.
 *
 * Hidden, an uncaught exception is logged as one line (class, message, file
 * and line) and the visitor gets a plain 500 page. The stack trace is left
 * out of the log on purpose: it carries function arguments.
 *
 * A response that has already said it is JSON (the v1 API, MCP, the token
 * endpoint) gets JSON_PAGE instead, so an API client still reads JSON.
 *
 * This file has no dependencies: prepend.php requires it before the
 * autoloader is registered.
 */
final class ErrorOutput
{
    public const string PAGE = '<h1>Something went wrong</h1>'
        . '<p>The error has been logged. Please try again later.</p>';

    public const string JSON_PAGE = '{"error":"Internal error"}';

    public static function hide(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
        set_exception_handler(self::handle(...));
    }

    public static function show(): void
    {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        // Back to PHP's own handler, which prints the exception.
        set_exception_handler(null);
    }

    public static function handle(\Throwable $e): void
    {
        error_log(self::logLine($e));
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo self::pageFor(headers_list());
    }

    /**
     * One line for the error log, without the stack trace.
     */
    public static function logLine(\Throwable $e): string
    {
        return 'Uncaught ' . $e::class . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine();
    }

    /**
     * The body for a request that failed: JSON when the response has already
     * said it is JSON, the plain page otherwise.
     *
     * @param list<string> $headers as headers_list() gives them
     */
    public static function pageFor(array $headers): string
    {
        foreach ($headers as $header) {
            if (preg_match('#^content-type:\s*application/json#i', $header) === 1) {
                return self::JSON_PAGE;
            }
        }
        return self::PAGE;
    }
}
