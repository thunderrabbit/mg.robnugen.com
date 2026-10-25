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

// Records Rob's calls only; nothing here touches DreamHost. prepend.php has
// already rejected any POST without the CSRF token.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/dh/databases.php');
    exit;
}

$browse = new \Inventory\Browse($mla_database);
$triage = new \Inventory\DatabaseTriage($mla_database);
$sweep = $browse->latestSweep();
$ids = array_values(array_filter(
    array_map(\Field::toInt(...), \Field::array($_POST, 'dh_database_id')),
    static fn(int $id): bool => $id > 0,
));
$marked = 0;
$refused = 0;

if (\Field::string($_POST, 'action') === 'unmark' && count($ids) === 1) {
    $triage->unmark($ids[0]);
} elseif (\Field::string($_POST, 'action') === 'mark' && $sweep !== null) {
    $verdict = \Inventory\EmptyDatabaseRule::check($ids, $browse->sizesInSweep($ids, $sweep['id']));
    if ($verdict->accepted !== []) {
        $triage->markForDeletion($verdict->accepted, 'empty: 0 bytes in the sweep of ' . $sweep['swept_on']);
    }
    $marked = count($verdict->accepted);
    $refused = count($verdict->refused);
}

header('Location: /admin/dh/databases.php?empty=1&marked=' . $marked . '&refused=' . $refused);
exit;
