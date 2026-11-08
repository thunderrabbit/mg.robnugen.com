<?php

/**
 * Which PHP serves this site, for the EC2 sweep (tools/ec2-sweep/deets.sh)
 * and nobody else: a bare version string to a request from this server's own
 * loopback address, 404 to everyone else. Behind a load balancer the public
 * arrives from the balancer's address, so loopback still means "the server
 * asking itself".
 *
 * Deliberately stands alone, without prepend.php: it has to answer whatever
 * state the application's bootstrap is in, and it must never touch the
 * database or a session. Step web_php_version_readable; see
 * tools/site-standards/NAMES.md.
 */

declare(strict_types=1);

$from = $_SERVER['REMOTE_ADDR'] ?? '';
if ($from !== '127.0.0.1' && $from !== '::1') {
    http_response_code(404);
    return;
}
header('Content-Type: text/plain; charset=utf-8');
echo PHP_VERSION;
