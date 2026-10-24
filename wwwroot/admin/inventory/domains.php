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

$type = \Field::string($_GET, 'type');
$php = \Field::string($_GET, 'php');
\Inventory\AdminPage::render($config, $is_logged_in, 'admin/inventory/domains.tpl.php', 'DreamHost domains', [
    'sweep' => $sweep,
    'filter_type' => $type,
    'filter_php' => $php,
    'filter_cert' => \Field::string($_GET, 'cert') === 'problem',
    'domains' => $browse->domains($type === '' ? null : $type, $php === '' ? null : $php, \Field::string($_GET, 'cert') === 'problem'),
]);
