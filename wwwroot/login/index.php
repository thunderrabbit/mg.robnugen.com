<?php

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \Mlaphp\Request $mla_request
 */

// This page is the ONLY place credentials are checked.
$login_error = '';
if (!$is_logged_in->isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(\Field::string($mla_request->post, 'username'));
    $password = \Field::string($mla_request->post, 'pass');
    $post_token = \Field::string($mla_request->post, 'csrf_token');
    $session_token = \Field::string($_SESSION, 'csrf_token');

    $result = \Auth\LoginResult::BadCredentials;
    if (!hash_equals($session_token, $post_token)) {
        $login_error = "Your session expired. Please log in again.";
    } elseif ($username !== '' && $password !== '') {
        $result = $is_logged_in->attemptPasswordLogin($username, $password);
    }

    if ($login_error === '' && $result !== \Auth\LoginResult::Success) {
        // One message for every bad credential. Saying which half was wrong
        // tells a guesser which usernames exist.
        $login_error = "Username or password incorrect.";
    }
}

if ($is_logged_in->isLoggedIn()) {
    // We logged in.. yay!
    // Check if there's a return URL saved
    if (isset($_SESSION['return_url'])) {
        $return_url = \Field::string($_SESSION, 'return_url');
        unset($_SESSION['return_url']); // Clear it
        // Only allow safe relative paths (must start with / but not //)
        if (!preg_match('#^/[^/]#', $return_url)) {
            $return_url = '/';
        }
    } else {
        // Default redirect based on user role
        // Admin and paid users go to dashboard (/), free users go to timer (/mg/)
        $return_url = ($is_logged_in->isAdmin() || $is_logged_in->isPaid()) ? '/' : '/mg/';
    }
    header(header: "Location: $return_url");
    exit;
} else {
    $page = new \Template(config: $config, is_logged_in: $is_logged_in);
    $page->setTemplate("layout/welcome_base.tpl.php");
    $page->set("page_title", "Log In - Meiso Gambare");

    // Get the inner content
    $inner_page = new \Template(config: $config, is_logged_in: $is_logged_in);
    $inner_page->setTemplate("login/login_content.tpl.php");

    // Check if user just registered
    $show_success = isset($_GET['registered']) && $_GET['registered'] === '1';
    $inner_page->set("show_success_message", $show_success);
    $inner_page->set("login_error", $login_error);

    $page->set("page_content", $inner_page->grabTheGoods());

    $page->echoToScreen();
    exit;
}
