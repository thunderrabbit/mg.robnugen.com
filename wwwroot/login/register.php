<?php

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


$mla_request = new \Mlaphp\Request();
$config = new \Config();

try {
    $config = new \Config();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // handle form submission...
    $mla_database = \Database\Base::getPDO($config);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['pass'] ?? '';

    // Validate input
    $errors = [];
    if (empty($username)) {
        $errors[] = "Username is required.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
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
    $is_logged_in = new \Auth\IsLoggedIn($mla_database, $config);
    $is_logged_in->checkLogin($mla_request);
    $page = new \Template(config: $config, is_logged_in: $is_logged_in);
    $page->setTemplate("layout/welcome_base.tpl.php");
    $page->set("page_title", "Create Account - Meiso Gambare");

    // Get the inner content
    $inner_page = new \Template(config: $config, is_logged_in: $is_logged_in);
    $inner_page->setTemplate("login/register_content.tpl.php");
    $page->set("page_content", $inner_page->grabTheGoods());

    $page->echoToScreen();
    exit;
}
