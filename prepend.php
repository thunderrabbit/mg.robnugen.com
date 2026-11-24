<?php

declare(strict_types=1);

require_once __DIR__ . '/version.php';

# Report everything, but to the server's error log, never the page: a shown
# warning hands visitors file paths, SQL and stack traces. First, before the
# autoloader, so the extension and session checks below are hidden too;
# Config may ask to see them, further down.
require_once __DIR__ . '/classes/Security/ErrorOutput.php';
\Security\ErrorOutput::hide();

# Before anything needs them: name a missing extension instead of dying later
# with "Call to undefined function". The autoloader isn't registered yet.
require_once __DIR__ . '/classes/Startup/RequiredExtensions.php';
$missing_extensions = \Startup\RequiredExtensions::missing(
    \Startup\RequiredExtensions::REQUIRED,
    extension_loaded(...),
);
if ($missing_extensions !== []) {
    http_response_code(500);
    exit(\Startup\RequiredExtensions::message($missing_extensions));
}

require_once __DIR__ . '/classes/Startup/Session.php';
\Startup\Session::start();

require_once __DIR__ . '/classes/Mlaphp/Autoloader.php';
// create autoloader instance and register the method with SPL
$autoloader = new \Mlaphp\Autoloader();
spl_autoload_register($autoloader->load(...));

$mla_request = new \Mlaphp\Request();
$csrfProtect = new \Security\CSRFProtectaroo($mla_request);
// Mint it now: older pages still read $_SESSION['csrf_token'] directly.
$csrfProtect->getToken();

// Every state-changing browser request must carry the session's token.
// Checked here, before any page code or DB work, so a handler cannot forget
// it. A cross-site POST fails because the attacking page cannot read our
// session's token.
//
// Exempt: callers that are not browsers and never use the session cookie.
// Each authenticates the request itself, so a forged cross-site request has
// nothing to ride on.
//   api/v1/index.php  API key in the Authorization header
//   mcp/index.php     OAuth bearer token
//   mcp/token.php     issues those tokens (client secret or PKCE)
// billing/webhook.php (Stripe, signed) and login/register.php never load
// this file, so the check does not reach them.
// Match on the script that is actually running, never on the URL: Apache
// resolves /mcp/%2e%2e/login/ to the login page while the raw URL still
// starts with /mcp/.
$csrf_exempt_scripts = [
    __DIR__ . '/wwwroot/api/v1/index.php',
    __DIR__ . '/wwwroot/mcp/index.php',
    __DIR__ . '/wwwroot/mcp/token.php',
];
$running_script = realpath(\Field::string($_SERVER, 'SCRIPT_FILENAME'));
$csrf_exempt = $running_script !== false
    && in_array($running_script, array_map(realpath(...), $csrf_exempt_scripts), true);
if (!$csrf_exempt && !$csrfProtect->validateRequest()) {
    http_response_code(403);
    echo "<h1>Request rejected</h1>"
        . "<p>The form's security token was missing or has expired. "
        . "Go back, reload the page, and try again.</p>";
    exit;
}

try {
    $config = new \Config();
    // @phpstan-ignore catch.neverThrown (the autoloader throws when Config.php is missing)
} catch (\Exception $e) {
    // The message names the missing file's path, so it is logged, not shown.
    error_log("Couldn't create Config: " . $e->getMessage());
    http_response_code(503);
    echo "<h1>Site unavailable</h1><p>This site is not configured yet.</p>";
    exit;
}

$show_errors = \Security\ErrorOutput::asked($config);
if ($show_errors) {
    \Security\ErrorOutput::show();
}

$mla_database = \Database\Base::getPDO($config);
// Check if the database exists and is accessible
$dbExistaroo = new \Database\DBExistaroo(
    config: $config,
    pdo: $mla_database,
);

$errors = $dbExistaroo->checkaroo();

$uri_path = $_SERVER['REQUEST_URI'] ?? '';

if (
    !empty($errors)
    && $errors[0] == "YallGotAnyMoreOfThemUsers"
    && $uri_path != "/login/register.php"
) {
    header(header: "Location: /login/register.php");
    exit;
}


if (!empty($errors)) {
    // These name the database and the configured domain. The operator reads
    // them in the error log, or on screen with $display_errors on.
    foreach ($errors as $error) {
        error_log("Setup problem: " . $error);
    }
    http_response_code(503);
    echo "<h1>Site unavailable</h1>";
    if ($show_errors) {
        echo "<ul>";
        foreach ($errors as $error) {
            echo "<li>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>This site is not set up correctly. The details are in its error log.</p>";
    }
    exit;
}

$is_logged_in = new \Auth\IsLoggedIn(
    $mla_database,
    $config,
    new \Database\CookieRepository($mla_database),
    new \Auth\LoginThrottle($mla_database),
);
$is_logged_in->resumeFromCookie($mla_request);

// What waits for an admin, drawn inside the menu's Admin link by every
// Template: pending migrations plus due notifications (mg #851). The pending
// list is read again on purpose: the one checkaroo() built is from before its
// auto-apply, so it is stale on exactly the requests that change it. Only a
// cookie login is counted: API keys and OAuth tokens never log in here, so
// they stay at 0 and no query runs for them.
$admin_count = 0;
if ($is_logged_in->isLoggedIn() && $is_logged_in->isAdmin()) {
    $admin_count = count($dbExistaroo->getPendingMigrations())
        + new \Admin\Notifications($mla_database)->dueCount();
}

// Check for and finish expired activity sessions
if ($is_logged_in->isLoggedIn()) {
    try {
        $akHelper = new \ActivityTracking\ActivityKai($mla_database);
        $akHelper->processExpiredTimers($is_logged_in->loggedInID());
    } catch (\Exception) {
        // Silently fail to avoid breaking page load, but maybe log it?
        // error_log("Failed to finish expired sessions: " . $e->getMessage());
    }
}
