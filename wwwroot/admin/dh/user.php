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

$user = $browse->user(\Field::int($_GET, 'dh_user_id'));
if ($user === null) {
    http_response_code(404);
    exit('No such user.');
}
$user_id = \Field::int($user, 'dh_user_id');
\Inventory\AdminPage::render($config, $is_logged_in, 'admin/dh/user.tpl.php', 'DreamHost user', [
    'sweep' => $sweep,
    'user' => $user,
    'states' => $browse->userStates($user_id),
    'usage' => $browse->userUsage($user_id),
    'sites' => $browse->userSites($user_id),
    'crons' => $browse->userCrons($user_id),
    'clues' => $browse->userAgeClues($user_id),
]);
