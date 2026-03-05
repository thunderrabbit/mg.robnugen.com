<?php

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if ($is_logged_in->isLoggedIn() && $is_logged_in->isAdmin()) {

    // Handle alert dismiss POST actions
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $action = $_POST['action'] ?? '';
        if ($action === 'dismiss_alerts') {
            \Admin\OmgAlerts::dismissAll($mla_database);
            header('Location: /admin/');
            exit;
        } elseif ($action === 'dismiss_alert' && !empty($_POST['omg_id'])) {
            \Admin\OmgAlerts::dismissOne($mla_database, (int) $_POST['omg_id']);
            header('Location: /admin/');
            exit;
        }
    }

    $page = new \Template(config: $config, is_logged_in: $is_logged_in);
    $page->setTemplate("admin/index.tpl.php");
    $page->set(name: "site_version", value: SENTIMENTAL_VERSION);
    $page->set(name: "username", value: $is_logged_in->getLoggedInUsername());

    $pending = $dbExistaroo->getPendingMigrations();
    $page->set(name: "has_pending_migrations", value: !empty($pending));

    $omg_alerts = \Admin\OmgAlerts::getUnread($mla_database);
    $page->set(name: "omg_alerts", value: $omg_alerts);
    $page->set(name: "csrf_token", value: $_SESSION['csrf_token']);

    $inner = $page->grabTheGoods();

    $layout = new \Template(config: $config, is_logged_in: $is_logged_in);
    $layout->setTemplate("layout/base.tpl.php");
    $layout->set("page_title", "Admin Dashboard");
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
