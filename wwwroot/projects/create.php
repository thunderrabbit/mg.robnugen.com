<?php

/**
 * Create Project Page
 * Displays the form on GET; handles creation on POST.
 * Admin/paid only. On success redirects to the new project's detail page.
 * Auto-joins the creator's human actor as RW member (matching the API's create flow).
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

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

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $error = "Name is required.";
    } elseif (mb_strlen($name) > 100) {
        $error = "Name must be 100 characters or fewer.";
    } else {
        $dup = $pdo->prepare("SELECT project_id FROM projects WHERE user_id = ? AND name = ?");
        $dup->execute([$user_id, $name]);
        if ($dup->fetch()) {
            $error = "A project named '" . htmlspecialchars($name) . "' already exists in your account.";
        } else {
            $aiu_stmt = $pdo->prepare(
                "SELECT aiu_id FROM agent_inbox_user
                 WHERE user_id = ? AND actor_type = 'human' LIMIT 1"
            );
            $aiu_stmt->execute([$user_id]);
            $human_aiu = (int) $aiu_stmt->fetchColumn();

            if ($human_aiu <= 0) {
                $error = "Your account has no human actor configured; contact admin.";
            } else {
                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare(
                        "INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)"
                    );
                    $ins->execute([$user_id, $name, $description !== '' ? $description : null]);
                    $new_project_id = (int) $pdo->lastInsertId();

                    $mem = $pdo->prepare(
                        "INSERT INTO project_members (project_id, member_aiu, can_read, can_write)
                         VALUES (?, ?, 1, 1)"
                    );
                    $mem->execute([$new_project_id, $human_aiu]);

                    $pdo->commit();
                    header("Location: /projects/view.php?project_id={$new_project_id}");
                    exit;
                } catch (\Exception $e) {
                    $pdo->rollBack();
                    $error = "Failed to create project.";
                }
            }
        }
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Create Project - Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("projects/create.tpl.php");
if ($error !== null) {
    $inner->set("error", $error);
}
if (isset($_POST['name'])) {
    $inner->set("form_name", $_POST['name']);
}
if (isset($_POST['description'])) {
    $inner->set("form_description", $_POST['description']);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
