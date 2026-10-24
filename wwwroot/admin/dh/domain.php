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

$domain = $browse->domain(\Field::int($_GET, 'dh_domain_id'));
if ($domain === null) {
    http_response_code(404);
    exit('No such domain.');
}
$domain_id = \Field::int($domain, 'dh_domain_id');
\Inventory\AdminPage::render($config, $is_logged_in, 'admin/dh/domain.tpl.php', 'DreamHost domain', [
    'sweep' => $sweep,
    'domain' => $domain,
    'sites' => $browse->domainSites($domain_id),
    'certs' => $browse->domainCerts($domain_id),
    'registrations' => $browse->domainRegistrations($domain_id),
]);
