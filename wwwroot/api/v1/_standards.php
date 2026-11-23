<?php

/**
 * Standards steps: which steps a site still owes on /admin/dh/standards.php
 * (mg #806). Read only; what this answers is what the page shows.
 *
 * Routes:
 *   GET /standards/site?checkout_path=rob/example.com   free, user 1 only, can_read_project
 *   GET /standards/site?shortcode=example               (one of the two, not both)
 *
 * Answers {site: null} when no site matches, {site: {...}} (Inventory\Standards\SiteAnswer)
 * when one does, and 409 with the shortcodes when a checkout holds more than one.
 *
 * Like the inventory pages (Inventory\AdminPage::guard), these tables map the
 * owner's whole hosting account, so only user 1's actors get an answer.
 *
 * Variables from index.php: $pdo, $auth_user_id, $method, $path
 */

declare(strict_types=1);

/**
 * Set up by index.php, which includes this file.
 *
 * @var \PDO $pdo
 * @var int $auth_user_id
 * @var string $method
 * @var string $path
 */

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if ($path !== '/standards/site') {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

if ($auth_user_id !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Standards are for the account owner only']);
    exit;
}

try {
    $query = \Inventory\Standards\SiteQuery::parse($_GET);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$found = \Inventory\Standards\SiteLookup::find($query, new \Inventory\Standards\Board($pdo));

if ($found->shortcodes !== []) {
    http_response_code(409);
    echo json_encode([
        'error' => 'More than one site uses that checkout; pass shortcode',
        'shortcodes' => $found->shortcodes,
    ]);
    exit;
}

echo json_encode(['site' => $found->site]);
