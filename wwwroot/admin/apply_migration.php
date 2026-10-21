<?php

declare(strict_types=1);

# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \Database\DBExistaroo $dbExistaroo
 */

header("Content-Type: application/json");

if (!$is_logged_in->isLoggedIn() || !$is_logged_in->isAdmin()) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$input = \Field::jsonArray((string) file_get_contents('php://input'));

if (empty($input['migration'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing migration identifier"]);
    exit;
}

try {
    $dbExistaroo->applyMigration(\Field::string($input, 'migration'));
    echo json_encode(["status" => "success", "applied" => $input['migration']]);
} catch (\Exception $e) {
    error_log("applyMigration failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Migration failed"]);
}
