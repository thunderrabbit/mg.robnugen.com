<?php

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

$debugLevel = intval(value: $_GET['debug']) ?? 0;
if($debugLevel > 0) {
    echo "<pre>Debug Level: $debugLevel</pre>";
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Check if we're at the root
if ($uri === '/' || $uri === '' || $uri === '/index.php') {
    if($is_logged_in->isLoggedIn() && $is_logged_in->isAdmin()){
        // Admin users - show admin dashboard
        $page = new \Template(config: $config, is_logged_in: $is_logged_in);
        $page->setTemplate("layout/base.tpl.php");
        $page->set("page_title", "Dashboard - Meiso Gambare");

        // Get the dashboard content
        $inner_page = new \Template(config: $config, is_logged_in: $is_logged_in);
        $inner_page->setTemplate("dashboard/paid_dashboard.tpl.php");
        $inner_page->set("is_admin", true);
        if (isset($_GET['msg'])) {
            $inner_page->set("msg", $_GET['msg']);
        }
        $page->set("page_content", $inner_page->grabTheGoods());

        $page->echoToScreen();
        exit;
    } else if($is_logged_in->isLoggedIn() && $is_logged_in->isPaid()){
        // Paid users - show user dashboard (no admin link)
        $page = new \Template(config: $config, is_logged_in: $is_logged_in);
        $page->setTemplate("layout/base.tpl.php");
        $page->set("page_title", "Dashboard - Meiso Gambare");

        // Get the dashboard content
        $inner_page = new \Template(config: $config, is_logged_in: $is_logged_in);
        $inner_page->setTemplate("dashboard/paid_dashboard.tpl.php");
        $inner_page->set("is_admin", false);
        if (isset($_GET['msg'])) {
            $inner_page->set("msg", $_GET['msg']);
        }
        $page->set("page_content", $inner_page->grabTheGoods());

        $page->echoToScreen();
        exit;
    } else {
        // Anonymous or free users - show welcome page
        $page = new \Template(config: $config, is_logged_in: $is_logged_in);
        $page->setTemplate("layout/welcome_base.tpl.php");
        $page->set("page_title", "Meiso Gambare - Meditation Timer");

        // Get the welcome content
        $inner_page = new \Template(config: $config, is_logged_in: $is_logged_in);
        $inner_page->setTemplate("welcome.tpl.php");
        $inner_page->set("is_logged_in", $is_logged_in->isLoggedIn());
        $inner_page->set("is_admin", $is_logged_in->isAdmin());
        $page->set("page_content", $inner_page->grabTheGoods());

        $page->echoToScreen();
        exit;
    }
}
// Otherwise, let them access /admin/, /profile/, /mg/, etc.
