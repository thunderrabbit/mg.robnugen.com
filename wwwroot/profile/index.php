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
$new_api_key = '';  // only populated immediately after generation — shown once

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
        $site_title = trim($_POST['site_title'] ?? '');
        $site_subtitle = trim($_POST['site_subtitle'] ?? '');
        $arrow_color_older = trim($_POST['arrow_color_older'] ?? '');
        $arrow_color_newer = trim($_POST['arrow_color_newer'] ?? '');
        $user_id = $is_logged_in->loggedInID();

        // Check if settings exist for user
        $stmt = $mla_database->prepare("SELECT setting_id FROM user_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $mla_database->prepare("UPDATE user_settings SET site_title = ?, site_subtitle = ?, arrow_color_older = ?, arrow_color_newer = ? WHERE user_id = ?");
            $stmt->execute([$site_title, $site_subtitle, $arrow_color_older, $arrow_color_newer, $user_id]);
        } else {
            $stmt = $mla_database->prepare("INSERT INTO user_settings (user_id, site_title, site_subtitle, arrow_color_older, arrow_color_newer) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $site_title, $site_subtitle, $arrow_color_older, $arrow_color_newer]);
        }
        $success_message = "Site settings updated successfully.";
    }

    // API Key Logic
    if (isset($_POST['api_key_action'])) {
        $user_id = $is_logged_in->loggedInID();
        $apiKeyHelper = new \Auth\ApiKey($mla_database);

        if ($_POST['api_key_action'] === 'generate') {
            $label = trim($_POST['api_key_label'] ?? '');
            $new_api_key = $apiKeyHelper->generateKey($user_id, $label);
            $success_message = "API key generated. Copy it now — it will not be shown again.";
        } elseif ($_POST['api_key_action'] === 'revoke') {
            $key_id = (int)($_POST['key_id'] ?? 0);
            if ($key_id > 0 && $apiKeyHelper->revokeKey($key_id, $user_id)) {
                $success_message = "API key revoked.";
            } else {
                $errors[] = "Could not revoke key.";
            }
        }
    }

    // Set error message if there are errors
    if (!empty($errors)) {
        // HTML escape each error message individually, then join with <br>
        $escaped_errors = array_map('htmlspecialchars', $errors);
        $error_message = implode("<br>", $escaped_errors);
    }
}

// Load API keys and credit balance for display
$user_id = $is_logged_in->loggedInID();
$apiKeyHelper = new \Auth\ApiKey($mla_database);
$api_keys = $apiKeyHelper->getKeysForUser($user_id);

$credits_stmt = $mla_database->prepare(
    "SELECT credits_remaining FROM api_credits WHERE user_id = ? LIMIT 1"
);
$credits_stmt->execute([$user_id]);
$credits_remaining = (int)($credits_stmt->fetchColumn() ?: 0);

// Display the form
$page = new \Template(config: $config, is_logged_in: $is_logged_in);
$page->setTemplate("profile/index.tpl.php");
$page->set("username", $is_logged_in->getLoggedInUsername());
$page->set("site_title", $is_logged_in->getSiteTitle());
$page->set("site_subtitle", $is_logged_in->getSiteSubtitle());
$page->set("arrow_color_older", $is_logged_in->getArrowColorOlder());
$page->set("arrow_color_newer", $is_logged_in->getArrowColorNewer());
$page->set("error_message", $error_message);
$page->set("success_message", $success_message);
$page->set("new_api_key", $new_api_key);
$page->set("api_keys", $api_keys);
$page->set("credits_remaining", $credits_remaining);

$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in);
$layout->setTemplate("layout/base.tpl.php");
$layout->set("username", $is_logged_in->getLoggedInUsername());
$layout->set("page_title", "Change Password");
$layout->set("page_content", $inner);
$layout->echoToScreen();
