<?php

/**
 * Create Repository Page
 * Displays the form on GET; handles creation on POST.
 * Admin/paid + human-only. The API (POST /repositories/create) is narrower:
 * a human key on an admin account, or an actor with can_write_repos.
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config\Settings $config
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \Security\CSRFProtectaroo $csrfProtect
 * @var int $admin_count
 */

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}
if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    header("Location: /?msg=upgrade_required");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$has_human = new \Auth\Actors($pdo)->hasHumanActor($user_id);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$has_human) {
        $error = "Your account has no human actor configured; contact admin.";
    } else {
        $name           = trim(\Field::string($_POST, 'name'));
        $url            = trim(\Field::string($_POST, 'url'));
        $default_branch = trim(\Field::string($_POST, 'default_branch')) ?: 'main';

        if ($name === '') {
            $error = "Name is required.";
        } elseif (mb_strlen($name) > 100) {
            $error = "Name must be 100 characters or fewer.";
        } elseif ($url !== '' && mb_strlen($url) > 500) {
            $error = "URL must be 500 characters or fewer.";
        } elseif (mb_strlen($default_branch) > 100) {
            $error = "Default branch must be 100 characters or fewer.";
        } else {
            $repositories = new \Issues\Repositories($pdo);
            if ($repositories->repoIdOfName($user_id, $name) !== null) {
                $error = "A repository named '" . htmlspecialchars($name) . "' already exists in your account.";
            } else {
                $repositories->addRepository($user_id, $name, $url !== '' ? $url : null, $default_branch);
                header("Location: /repositories/?msg=repository_created");
                exit;
            }
        }
    }
}

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Create Repository - Meiso Gambare");

$inner = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$inner->setTemplate("repositories/create.tpl.php");
$inner->set("has_human", $has_human);
if ($error !== null) {
    $inner->set("error", $error);
}
if (isset($_POST['name'])) {
    $inner->set("form_name", $_POST['name']);
}
if (isset($_POST['url'])) {
    $inner->set("form_url", $_POST['url']);
}
if (isset($_POST['default_branch'])) {
    $inner->set("form_default_branch", $_POST['default_branch']);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
