<?php

declare(strict_types=1);

# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Auth\IsLoggedIn $is_logged_in
 */

// Logout the user
$is_logged_in->logout();

// Redirect to home page
header("Location: /");
exit;
