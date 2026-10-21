<?php

declare(strict_types=1);

// This is only run if users table is empty
// We do *not* include prepend.php because
// it would cause a circular dependency

# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/classes/Mlaphp/Autoloader.php';
include_once $matches[1] . '/version.php';
// create autoloader instance and register the method with SPL
$autoloader = new \Mlaphp\Autoloader();
spl_autoload_register(array($autoloader, 'load'));


\Startup\Session::start();
$mla_request = new \Mlaphp\Request();
// prepend.php's CSRF gate never runs here, so check the token ourselves:
// otherwise any site could create accounts in a visitor's browser.
$csrfProtect = new \Security\CSRFProtectaroo($mla_request);

try {
    $config = new \Config();
    // @phpstan-ignore catch.neverThrown (the autoloader throws when Config.php is missing)
} catch (\Exception $e) {
    error_log("Config init failed: " . $e->getMessage());
    echo "Configuration error. Please contact the administrator.";
    exit;
}

$mla_database = \Database\Base::getPDO($config);
// Check if the database exists and is accessible
$dbExistaroo = new \Database\DBExistaroo(
    config: $config,
    pdo: $mla_database,
);

$creating_admin_user = !$dbExistaroo->firstUserExistBool();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfProtect->validateRequest()) {
    http_response_code(403);
    echo "<h1>Request rejected</h1>"
        . "<p>The form's security token was missing or has expired. "
        . "Go back, reload the page, and try again.</p>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // handle form submission...
    $mla_database = \Database\Base::getPDO($config);
    $username = trim(\Field::string($_POST, 'username'));
    $password = \Field::string($_POST, 'pass');

    // Validate input
    $errors = [];
    if (empty($username)) {
        $errors[] = "Username is required.";
    } elseif (\Auth\Username::problem($username) !== '') {
        $errors[] = \Auth\Username::problem($username);
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (\Auth\PasswordPolicy::problem($password) !== '') {
        $errors[] = \Auth\PasswordPolicy::problem($password);
    }

    $signup_throttle = new \Auth\SignupThrottle($mla_database);
    $signup_ip = \Field::string($_SERVER, 'REMOTE_ADDR');
    if (empty($errors) && $signup_throttle->isThrottled($signup_ip)) {
        $errors[] = "Too many new accounts were created recently. Please try again in "
            . intdiv(\Auth\SignupThrottle::WINDOW_SECONDS, 60) . " minutes.";
    }

    // If errors, redisplay form with errors
    if (!empty($errors)) {
        echo "<h1>Registration Errors</h1><ul>";
        foreach ($errors as $e) {
            echo "<li>" . htmlspecialchars($e) . "</li>";
        }
        echo "</ul><a href=\"/\">Go back</a>";
        exit;
    }

    // Hash password
    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $role = $creating_admin_user ? "admin" : "user";
        $stmt = $mla_database->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
        $stmt->execute([$username, $hash, $role]);

        // Seed 100 trial API credits for new user
        $new_user_id = $mla_database->lastInsertId();
        $trial_stmt = $mla_database->prepare(
            "INSERT INTO api_credits (user_id, credits_remaining) VALUES (?, 100)"
        );
        $trial_stmt->execute([$new_user_id]);
        $signup_throttle->recordSignup($signup_ip);

        // Redirect to login page with success message
        header("Location: /login/?registered=1");
        exit;
    } catch (\PDOException $e) {
        if ($e->getCode() == '23000') { // Duplicate key error
            echo "<h1>Error</h1><p>An account with that username already exists. Did you mean to <a href=\"/login/\">log in</a>?</p>";
        } else {
            echo "<h1>Error</h1><p>Registration failed. Please try again.</p>";
        }
    }

    exit;
} else {
    $is_logged_in = new \Auth\IsLoggedIn(
        $mla_database,
        $config,
        new \Database\CookieRepository($mla_database),
        new \Auth\LoginThrottle($mla_database),
    );
    $is_logged_in->resumeFromCookie($mla_request);
    $page = new \Template(config: $config, is_logged_in: $is_logged_in);
    $page->setTemplate("layout/welcome_base.tpl.php");
    $page->set("page_title", "Create Account - Meiso Gambare");

    // Get the inner content
    $inner_page = new \Template(config: $config, is_logged_in: $is_logged_in);
    $inner_page->setTemplate("login/register_content.tpl.php");
    $inner_page->set("csrf_field", $csrfProtect->field());
    $page->set("page_content", $inner_page->grabTheGoods());

    $page->echoToScreen();
    exit;
}
