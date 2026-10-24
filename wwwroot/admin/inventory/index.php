<?php

declare(strict_types=1);

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \PDO $mla_database
 */

\Inventory\AdminPage::guard($is_logged_in);
$browse = new \Inventory\Browse($mla_database);
$sweep = $browse->latestSweep();

$swept_on = $sweep['swept_on'] ?? '';
\Inventory\AdminPage::render($config, $is_logged_in, 'admin/inventory/index.tpl.php', 'DreamHost inventory', [
    'sweep' => $sweep,
    'counts' => $sweep === null ? [] : $browse->counts(),
    'php_summary' => $browse->phpSummary(),
    'cert_problems' => $browse->certProblems(),
    'cert_expiries' => $sweep === null ? [] : $browse->certExpiries($swept_on, 8),
    'registrations' => $sweep === null ? [] : $browse->registrations($swept_on),
    'shared_users' => $browse->sharedUsers(),
]);
