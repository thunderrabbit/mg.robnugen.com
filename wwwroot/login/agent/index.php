<?php

# Must include here because DH runs FastCGI
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if ($is_logged_in->isLoggedIn()) {
    header("Location: /projects/");
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_token    = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    if (!hash_equals($session_token, $post_token)) {
        $error_message = 'Session expired. Please reload and try again.';
    } else {
        $user_id_raw = $_POST['user_id'] ?? '';
        $aiu_id_raw  = $_POST['aiu_id'] ?? '';
        $password    = $_POST['password'] ?? '';

        if (!ctype_digit((string)$user_id_raw) || !ctype_digit((string)$aiu_id_raw) || $password === '') {
            $error_message = 'All fields are required (numeric user_id, numeric aiu_id, password).';
        } else {
            $user_id = (int)$user_id_raw;
            $aiu_id  = (int)$aiu_id_raw;
            if ($is_logged_in->attemptAIULogin($user_id, $aiu_id, $password)) {
                header("Location: /projects/");
                exit;
            }
            $error_message = 'Invalid credentials.';
        }
    }
}

$page = new \Template(config: $config, is_logged_in: $is_logged_in);
$page->setTemplate("layout/welcome_base.tpl.php");
$page->set("page_title", "Agent Log In - Meiso Gambare");

$inner_page = new \Template(config: $config, is_logged_in: $is_logged_in);
$inner_page->setTemplate("login/agent_content.tpl.php");
$inner_page->set("error_message", $error_message);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
