<?php

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if ($is_logged_in->isLoggedIn() && $is_logged_in->isAdmin()) {
    $log_file = $config->app_path . '/auth_log.txt';

    // Read the last 100 lines from the log file
    $log_lines = [];
    if (file_exists($log_file)) {
        $file_content = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $log_lines = array_slice($file_content, -100);
        $log_lines = array_reverse($log_lines); // Show newest first
    }

    $page = new \Template(config: $config, is_logged_in: $is_logged_in);
    $page->setTemplate("admin/auth_log.tpl.php");
    $page->set(name: "log_lines", value: $log_lines);
    $page->set(name: "log_file", value: $log_file);
    $inner = $page->grabTheGoods();

    $layout = new \Template(config: $config, is_logged_in: $is_logged_in);
    $layout->setTemplate("layout/base.tpl.php");
    $layout->set("page_title", "Admin Auth Log");
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
