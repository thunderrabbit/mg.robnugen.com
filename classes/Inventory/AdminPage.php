<?php

declare(strict_types=1);

namespace Inventory;

/**
 * The shell around the DreamHost inventory pages (mg #775). These tables map
 * Rob's whole hosting account, so only user 1 gets past guard(): being an
 * admin is not enough.
 */
final class AdminPage
{
    /**
     * Sends everyone but user 1 away and does not return for them.
     */
    public static function guard(\Auth\IsLoggedIn $is_logged_in): void
    {
        if (!$is_logged_in->isLoggedIn()) {
            $_SESSION['return_url'] = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/admin/';
            header('Location: /login/');
            exit;
        }
        if (!$is_logged_in->isAdmin() || $is_logged_in->loggedInID() !== 1) {
            http_response_code(403);
            echo '<h1>Access Denied</h1><p>The DreamHost inventory is for its owner only.</p>';
            exit;
        }
    }

    /**
     * @param array<string, mixed> $vars handed to the template
     */
    public static function render(
        \Config $config,
        \Auth\IsLoggedIn $is_logged_in,
        string $template,
        string $title,
        array $vars,
    ): never {
        $page = new \Template(config: $config, is_logged_in: $is_logged_in);
        $page->setTemplate($template);
        foreach ($vars as $name => $value) {
            $page->set(name: $name, value: $value);
        }
        $inner = $page->grabTheGoods();

        $layout = new \Template(config: $config, is_logged_in: $is_logged_in);
        $layout->setTemplate('layout/base.tpl.php');
        $layout->set('page_title', $title);
        $layout->set('page_content', $inner);
        $layout->echoToScreen();
        exit;
    }

    public static function h(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES);
    }

    /**
     * A byte count the way a person reads it: 4.4 MB.
     */
    public static function bytes(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '';
        }
        $bytes = (float) $value;
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return $unit === 'B' ? number_format($bytes) . ' B' : number_format($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }
        return '';
    }
}
