<?php

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if ($is_logged_in->isLoggedIn() && $is_logged_in->isAdmin()) {
    $page = new \Template(config: $config);
    $page->setTemplate("admin/index.tpl.php");
    $page->set(name: "site_version", value: SENTIMENTAL_VERSION);
    $page->set(name: "username", value: $is_logged_in->getLoggedInUsername());

    $pending = $dbExistaroo->getPendingMigrations();
    $page->set(name: "has_pending_migrations", value: !empty($pending));
    $inner = $page->grabTheGoods();

    $layout = new \Template(config: $config);
    $layout->setTemplate("layout/admin_base.tpl.php");
    $layout->set("page_title", "Dashboard");
    $layout->set("page_content", $inner);
    $layout->echoToScreen();
    exit;
} else {
    // Check if user is logged in but not admin
    if ($is_logged_in->isLoggedIn()) {
        // Logged in but not admin - show error
        echo "<h1>Access Denied</h1>";
        echo "<p>You need administrator privileges to access this page.</p>";
        echo "<p>Logged in as: <strong>" . htmlspecialchars($is_logged_in->getLoggedInUsername()) . "</strong></p>";
        echo "<p><a href='/mg/'>Return to Meditation Timer</a> | <a href='/logout.php'>Logout</a></p>";
        exit;
    }

    // Not logged in - save URL and redirect to login
    $_SESSION['return_url'] = $_SERVER['REQUEST_URI'];
    header(header: "Location: /login/");
    exit;
}
