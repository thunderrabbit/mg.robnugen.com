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

$isEmpty = static fn(array $row): bool => $row['size_bytes'] !== null && \Field::toInt($row['size_bytes']) === 0;
$databases = $browse->databases($sweep['id'] ?? null, $sweep['previous_id'] ?? null);
$emptyCount = count(array_filter($databases, $isEmpty));
$emptyOnly = \Field::int($_GET, 'empty') === 1;

\Inventory\AdminPage::render($config, $is_logged_in, 'admin/dh/databases.tpl.php', 'DreamHost databases', [
    'sweep' => $sweep,
    'databases' => $emptyOnly ? array_values(array_filter($databases, $isEmpty)) : $databases,
    'empty_count' => $emptyCount,
    'empty_only' => $emptyOnly,
    'marked_databases' => $browse->markedDatabases(),
    'just_marked' => \Field::int($_GET, 'marked'),
    'just_refused' => \Field::int($_GET, 'refused'),
]);
