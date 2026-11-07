<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Which site an API caller means: by its checkout (relative to ~/work, as
 * std_sites.checkout_path holds it) or by its shortcode. Exactly one is set.
 */
final readonly class SiteQuery
{
    private function __construct(public ?string $shortcode, public ?string $checkoutPath)
    {
    }

    /**
     * @param array<mixed> $query the request's query parameters
     * @throws \InvalidArgumentException with a message fit for the caller
     */
    public static function parse(array $query): self
    {
        $path = $query['checkout_path'] ?? null;
        $shortcode = $query['shortcode'] ?? null;
        $path = $path === '' ? null : $path;
        $shortcode = $shortcode === '' ? null : $shortcode;

        if ($path !== null && $shortcode !== null) {
            throw new \InvalidArgumentException('Give checkout_path or shortcode, not both');
        }
        if ($shortcode !== null) {
            if (!is_string($shortcode) || !Names::isShortcode($shortcode)) {
                throw new \InvalidArgumentException('Not a shortcode');
            }
            return new self($shortcode, null);
        }
        if ($path !== null) {
            if (!is_string($path) || !Names::isCheckoutPath($path)) {
                throw new \InvalidArgumentException('checkout_path is relative to ~/work, like rob/example.com');
            }
            return new self(null, $path);
        }
        throw new \InvalidArgumentException('Give checkout_path or shortcode');
    }
}
