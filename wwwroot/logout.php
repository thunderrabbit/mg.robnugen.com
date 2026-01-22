<?php

# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Logout the user
$is_logged_in->logout();

// Redirect to home page
header("Location: /");
exit;
