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
 */

// Only a POST logs out: prepend.php checks its CSRF token, so another site
// cannot sign someone out by linking or framing this URL. A GET (an old link
// or bookmark) shows a one-button confirmation instead.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_logged_in->logout();
    header(header: "Location: /");
    exit;
}

if (!$is_logged_in->isLoggedIn()) {
    header(header: "Location: /");
    exit;
}

$layout = new \Template(config: $config, is_logged_in: $is_logged_in);
$layout->setTemplate("layout/base.tpl.php");
$layout->set("page_title", "Log out");
$layout->set(
    "page_content",
    '<div class="PagePanel"><form method="POST" action="/logout/">' . csrf_field()
    . '<button type="submit">Log out</button></form></div>'
);
$layout->echoToScreen();
