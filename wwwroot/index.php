<?php

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

$debugLevel = intval(value: $_GET['debug']) ?? 0;
if($debugLevel > 0) {
    echo "<pre>Debug Level: $debugLevel</pre>";
}

$uri = $_SERVER['REQUEST_URI'] ?? '/';

// Check if we're at the root
if ($uri === '/' || $uri === '') {
    if($is_logged_in->isLoggedIn() && $is_logged_in->isAdmin()){
        // Admin users - show dashboard
        $page = new \Template(config: $config);
        $page->setTemplate("layout/welcome_base.tpl.php");
        $page->set("page_title", "Dashboard - Meiso Gambare");

        // Get the dashboard content
        $inner_page = new \Template(config: $config);
        $inner_page->setTemplate("dashboard/active_sessions.tpl.php");
        $page->set("page_content", $inner_page->grabTheGoods());

        $page->echoToScreen();
        exit;
    } else {
        // Anonymous or free users - show welcome page
        $page = new \Template(config: $config);
        $page->setTemplate("layout/welcome_base.tpl.php");
        $page->set("page_title", "Meiso Gambare - Meditation Timer");

        // Get the welcome content
        $inner_page = new \Template(config: $config);
        $inner_page->setTemplate("welcome.tpl.php");
        $inner_page->set("is_logged_in", $is_logged_in->isLoggedIn());
        $inner_page->set("is_admin", $is_logged_in->isAdmin());
        $page->set("page_content", $inner_page->grabTheGoods());

        $page->echoToScreen();
        exit;
    }
}
// Otherwise, let them access /admin/, /profile/, /mg/, etc.
