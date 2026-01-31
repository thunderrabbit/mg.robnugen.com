<?php

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Check if user is logged in
if (!$is_logged_in->isLoggedIn()) {
    $_SESSION['return_url'] = $_SERVER['REQUEST_URI'];
    header("Location: /login/");
    exit;
}

$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Password Change Logic
    if (isset($_POST['change_password_action'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate input
        $errors = [];
        if (empty($current_password)) {
            $errors[] = "Current password is required.";
        }
        if (empty($new_password)) {
            $errors[] = "New password is required.";
        }
        if (empty($confirm_password)) {
            $errors[] = "Password confirmation is required.";
        }
        if ($new_password !== $confirm_password) {
            $errors[] = "New passwords do not match.";
        }

        // If no validation errors, proceed with password change
        if (empty($errors)) {
            try {
                $user_id = $is_logged_in->loggedInID();

                // Use PasswordRepository to handle password change
                $passwordRepository = new \Database\PasswordRepository($mla_database);
                $result = $passwordRepository->changePassword($user_id, $current_password, $new_password);

                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $errors[] = $result['message'];
                }
            } catch (\Exception $e) {
                $errors[] = "An error occurred while changing password: " . $e->getMessage();
            }
        }
    }

    // Site Settings Logic
    if (isset($_POST['update_settings_action'])) {
        $site_title = trim($_POST['site_title'] ?? '');
        $site_subtitle = trim($_POST['site_subtitle'] ?? '');
        $user_id = $is_logged_in->loggedInID();

        // Check if settings exist for user
        $stmt = $mla_database->prepare("SELECT setting_id FROM user_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $mla_database->prepare("UPDATE user_settings SET site_title = ?, site_subtitle = ? WHERE user_id = ?");
            $stmt->execute([$site_title, $site_subtitle, $user_id]);
        } else {
            $stmt = $mla_database->prepare("INSERT INTO user_settings (user_id, site_title, site_subtitle) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $site_title, $site_subtitle]);
        }
        $success_message = "Site settings updated successfully.";
    }

    // Set error message if there are errors
    if (!empty($errors)) {
        // HTML escape each error message individually, then join with <br>
        $escaped_errors = array_map('htmlspecialchars', $errors);
        $error_message = implode("<br>", $escaped_errors);
    }
}

// Display the form
$page = new \Template(config: $config);
$page->setTemplate("profile/index.tpl.php");
$page->set("username", $is_logged_in->getLoggedInUsername());
$page->set("site_title", $is_logged_in->getSiteTitle());
$page->set("site_subtitle", $is_logged_in->getSiteSubtitle());
$page->set("error_message", $error_message);
$page->set("success_message", $success_message);

$inner = $page->grabTheGoods();

$layout = new \Template(config: $config);
$layout->setTemplate("layout/base.tpl.php");
$layout->set("username", $is_logged_in->getLoggedInUsername());
$layout->set("page_title", "Change Password");
$layout->set("page_content", $inner);
$layout->echoToScreen();
