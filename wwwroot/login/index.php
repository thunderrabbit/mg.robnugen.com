<?php

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if ($is_logged_in->isLoggedIn()) {
    // We logged in.. yay!
    // Check if there's a return URL saved
    $return_url = $_SESSION['return_url'] ?? '/mg/';
    unset($_SESSION['return_url']); // Clear it
    header(header: "Location: $return_url");
    exit;
} else {
    if(!$is_logged_in->isLoggedIn()){
        $page = new \Template(config: $config);
        $page->setTemplate("layout/welcome_base.tpl.php");
        $page->set("page_title", "Log In - Meiso Gambare");

        // Get the inner content
        $inner_page = new \Template(config: $config);
        $inner_page->setTemplate("login/login_content.tpl.php");

        // Check if user just registered
        $show_success = isset($_GET['registered']) && $_GET['registered'] === '1';
        $inner_page->set("show_success_message", $show_success);

        $page->set("page_content", $inner_page->grabTheGoods());

        $page->echoToScreen();
        exit;
    }
}
