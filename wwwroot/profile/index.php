<?php

declare(strict_types=1);

# Must include here because DH runs FastCGI https://www.phind.com/search?cache=zfj8o8igbqvaj8cm91wp1b7k
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \PDO $mla_database
 * @var \Security\CSRFProtectaroo $csrfProtect
 * @var int $admin_count
 */

// Check if user is logged in
if (!$is_logged_in->isLoggedIn()) {
    $_SESSION['return_url'] = $_SERVER['REQUEST_URI'];
    header("Location: /login/");
    exit;
}

$error_message = '';
$success_message = '';
/** @var list<string> $errors Validation errors, read by both POST branches below. */
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Password Change Logic
    if (isset($_POST['change_password_action'])) {
        $current_password = \Field::string($_POST, 'current_password');
        $new_password = \Field::string($_POST, 'new_password');
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate input
        if (empty($current_password)) {
            $errors[] = "Current password is required.";
        }
        if (empty($new_password)) {
            $errors[] = "New password is required.";
        } else {
            // Only the new password: the current one may predate the policy.
            $errors = [...$errors, ...\Auth\PasswordPolicy::problems($new_password)];
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

                // The current-password check is a password guess like any
                // login, so it shares the login throttle: a stolen cookie
                // must not buy unlimited guesses at the real password.
                $throttle = new \Auth\LoginThrottle($mla_database);
                $throttle_name = $is_logged_in->getLoggedInUsername();
                $throttle_ip = \Field::string($_SERVER, 'REMOTE_ADDR', 'unknown');
                // admit() counts this guess before the password is checked,
                // so parallel requests cannot all slip in under the limit.
                if (!$throttle->admit($throttle_name, $throttle_ip)) {
                    $result = ['success' => false, 'message' => 'Too many failed attempts. Wait '
                        . intdiv(\Auth\LoginThrottle::WINDOW_SECONDS, 60) . ' minutes and try again.'];
                } else {
                    // Use PasswordRepository to handle password change
                    $passwordRepository = new \Database\PasswordRepository($mla_database);
                    $result = $passwordRepository->changePassword($user_id, $current_password, $new_password);
                    if ($result['success']) {
                        $throttle->clearFailures($throttle_name);
                    }
                }

                if ($result['success']) {
                    // Anyone holding an old session is signed out; this browser stays in.
                    $is_logged_in->revokeOtherSessions();
                    // OAuth connections (the phone connector) are credentials
                    // too: a leaked refresh token must not outlive the password.
                    new \Auth\ConnectedApps($mla_database)->revokeAll($user_id);
                    $success_message = $result['message'];
                } else {
                    $errors[] = $result['message'];
                }
            } catch (\Exception $e) {
                error_log("changePassword failed for user " . \Field::toString($user_id ?? 'unknown') . ": " . $e->getMessage());
                $errors[] = "An error occurred while changing password. Please try again.";
            }
        }
    }

    // Site Settings Logic
    if (isset($_POST['update_settings_action'])) {
        $site_title = trim(\Field::string($_POST, 'site_title'));
        $site_subtitle = trim(\Field::string($_POST, 'site_subtitle'));
        $user_id = $is_logged_in->loggedInID();

        // Check if settings exist for user
        $user_settings = new \Database\UserSettings($mla_database);

        if ($user_settings->hasSettings($user_id)) {
            $user_settings->updateSite($user_id, $site_title, $site_subtitle);
        } else {
            $user_settings->addSite($user_id, $site_title, $site_subtitle);
        }
        $success_message = "Site settings updated successfully.";
    }

    // Set error message if there are errors
    if (!empty($errors)) {
        // HTML escape each error message individually, then join with <br>
        $escaped_errors = array_map(htmlspecialchars(...), $errors);
        $error_message = implode("<br>", $escaped_errors);
    }
}

// Display the form
$page = new \Template(config: $config, is_logged_in: $is_logged_in, csrf: $csrfProtect, admin_count: $admin_count);
$page->setTemplate("profile/index.tpl.php");
$page->set("username", $is_logged_in->getLoggedInUsername());
$page->set("site_title", $is_logged_in->getSiteTitle());
$page->set("site_subtitle", $is_logged_in->getSiteSubtitle());
$page->set("error_message", $error_message);
$page->set("success_message", $success_message);

$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in, csrf: $csrfProtect, admin_count: $admin_count);
$layout->setTemplate("layout/base.tpl.php");
$layout->set("username", $is_logged_in->getLoggedInUsername());
$layout->set("page_title", "Change Password");
$layout->set("page_content", $inner);
$layout->echoToScreen();
