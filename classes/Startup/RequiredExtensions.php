<?php

declare(strict_types=1);

namespace Startup;

/**
 * The PHP extensions mg cannot run without, so a host whose PHP build lacks
 * one fails with its name and not with "Call to undefined function".
 * Keep the list in step with the ext-* entries in composer.json.
 */
final class RequiredExtensions
{
    public const REQUIRED = ['session', 'pdo_mysql', 'mbstring', 'curl', 'sodium'];

    /**
     * @param list<string>         $required
     * @param callable(string): bool $isLoaded e.g. extension_loaded(...)
     * @return list<string> the required extensions that are not loaded
     */
    public static function missing(array $required, callable $isLoaded): array
    {
        return array_values(array_filter(
            $required,
            static fn (string $extension): bool => !$isLoaded($extension),
        ));
    }

    /**
     * @param list<string> $missing
     */
    public static function message(array $missing): string
    {
        return 'mg cannot start: this PHP build is missing the extension(s): '
            . implode(', ', $missing)
            . '. Check the PHP version and its extensions in the host panel.';
    }
}
