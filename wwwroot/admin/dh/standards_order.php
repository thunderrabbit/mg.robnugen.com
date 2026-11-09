<?php

declare(strict_types=1);

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \PDO $mla_database
 */

// Saves a drop on the standards board: which sites now sit in each block it
// touched, top first. prepend.php has already refused a POST without the
// CSRF token, which the page sends as a header.
\Inventory\AdminPage::guard($is_logged_in);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$sent = \Field::jsonArray((string) file_get_contents('php://input'));
$slug = \Field::string($sent, 'standard');
$standard = null;
foreach (new \Inventory\Standards\Board($mla_database)->standards() as $candidate) {
    if ($candidate['slug'] === $slug) {
        $standard = $candidate;
    }
}
if ($standard === null) {
    http_response_code(400);
    echo json_encode(['error' => 'No standard is called that.']);
    exit;
}

$hostOf = array_column($standard['sites'], 'host', 'shortcode');
try {
    $plan = \Inventory\Standards\SiteOrder::plan($hostOf, \Inventory\Standards\SiteOrder::blocksFrom($sent['blocks'] ?? null));
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

try {
    new \Inventory\Standards\SiteOrderStore($mla_database)->save($plan);
} catch (\PDOException $e) {
    error_log('standards_order failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The order could not be stored.']);
    exit;
}
echo json_encode(['saved' => count($plan)]);
