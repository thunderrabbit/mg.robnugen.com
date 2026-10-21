<?php

declare(strict_types=1);

require_once __DIR__ . '/version.php';

# Report everything, but to the server's error log, never the page: a shown
# warning hands visitors file paths, SQL and stack traces.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

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
spl_autoload_register(array($autoloader, 'load'));

$mla_request = new \Mlaphp\Request();
$csrfProtect = new \Security\CSRFProtectaroo($mla_request);
// Mint it now: older pages still read $_SESSION['csrf_token'] directly.
$csrfProtect->getToken();

/**
 * The hidden CSRF input for any POST form. Templates write <?= csrf_field() ?>
 * inside the <form>; no page-level set() is needed.
 */
function csrf_field(): string
{
    /** @var \Security\CSRFProtectaroo $csrfProtect */
    global $csrfProtect;
    return $csrfProtect->field();
}

/**
 * The bare token, for JavaScript that sends it as the X-CSRF-Token header.
 * Always json_encode() it into JS or htmlspecialchars() it into an attribute.
 */
function csrf_token(): string
{
    /** @var \Security\CSRFProtectaroo $csrfProtect */
    global $csrfProtect;
    return $csrfProtect->getToken();
}

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
    && in_array($running_script, array_map('realpath', $csrf_exempt_scripts), true);
if (!$csrf_exempt && !$csrfProtect->validateRequest()) {
    http_response_code(403);
    echo "<h1>Request rejected</h1>"
        . "<p>The form's security token was missing or has expired. "
        . "Go back, reload the page, and try again.</p>";
    exit;
}

function print_roblog(mixed $message, string $label = ''): void
{
    $prefix = $label ? "[$label] " : '';
    $line = date('Y-m-d H:i:s') . " {$prefix}" . (is_string($message) ? $message : print_r($message, true)) . "\n";
    preg_match('#^(/home/[^/]+)#', __DIR__, $m);
    $logfile = !empty($m[1]) ? $m[1] . '/rob.log' : sys_get_temp_dir() . '/rob.log';
    @file_put_contents($logfile, $line, FILE_APPEND);
}

function print_rob(mixed $object, bool $exit = true): void
{
    echo "<pre>";
    if (is_object($object) && method_exists($object, "toArray")) {
        echo "ResultSet => " . print_r($object->toArray(), true);
    } else {
        print_r($object);
    }
    echo "</pre>";
    if ($exit) {
        exit;
    }
}

try {
    $config = new \Config();
    // @phpstan-ignore catch.neverThrown (the autoloader throws when Config.php is missing)
} catch (\Exception $e) {
    echo "Couldn't create Config cause " . $e->getMessage();
    exit;
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
    echo "<h1>Database Errors</h1>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
    exit;
}

$is_logged_in = new \Auth\IsLoggedIn(
    $mla_database,
    $config,
    new \Database\CookieRepository($mla_database),
    new \Auth\LoginThrottle($mla_database),
);
$is_logged_in->resumeFromCookie($mla_request);

// Check for and finish expired activity sessions
if ($is_logged_in->isLoggedIn()) {
    try {
        $akHelper = new \ActivityTracking\ActivityKai($mla_database);
        $akHelper->processExpiredTimers($is_logged_in->loggedInID());
    } catch (\Exception $e) {
        // Silently fail to avoid breaking page load, but maybe log it?
        // error_log("Failed to finish expired sessions: " . $e->getMessage());
    }
}
