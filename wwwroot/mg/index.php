<?php
# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Check URL for session key pattern: /mg/{session_key}
$uri = $_SERVER['REQUEST_URI'] ?? '';
if (preg_match('#^/mg/([a-zA-Z0-9_-]{11})(?:\?.*)?$#', $uri, $matches)) {
    $session_key = $matches[1];

    // Initialize database connection and helper
    $pdo = \Database\Base::getPDO($config);
    $sessionKeyHelper = new \ActivityTracking\SessionKey($pdo);

    // Verify ownership if logged in
    if ($is_logged_in->isLoggedIn()) {
        $user_id = $is_logged_in->loggedInID();

        // Check if user owns this session
        if (!$sessionKeyHelper->isOwner($session_key, $user_id)) {
            // Not owner - check if it's public
            $publicSession = $sessionKeyHelper->getPublicSessionByKey($session_key);
            if (!$publicSession) {
                // Not public or doesn't exist - redirect to /mg/
                header("Location: /mg/");
                exit;
            }
            // Session is public - continue to show public view
        }
        // User owns this session - continue to show timer page
    } else {
        // Not logged in - check if session is public
        $publicSession = $sessionKeyHelper->getPublicSessionByKey($session_key);
        if (!$publicSession) {
            // Not public or doesn't exist - redirect to /mg/
            header("Location: /mg/");
            exit;
        }
        // Session is public - continue to show public view
    }
}

// Check for todo_id in URL and verify ownership
$todo_id = $_GET['todo_id'] ?? null;
if ($todo_id && $is_logged_in->isLoggedIn()) {
    $pdo = \Database\Base::getPDO($config); // Ensure PDO is available
    $todoHelper = new \ActivityTracking\Todo($pdo);
    if (!$todoHelper->verifyOwnership((int)$todo_id, $is_logged_in->loggedInID())) {
        header("Location: /mg/?yeah=nah");
        exit;
    }
}

// Use Template system for rendering
$page = new \Template(config: $config, is_logged_in: $is_logged_in);
$page->setTemplate("layout/mg_base.tpl.php");
$page->set("page_title", "Meditation Timer - Meiso Gambare");

// Get the inner content
$inner_page = new \Template(config: $config, is_logged_in: $is_logged_in);
$inner_page->setTemplate("mg/timer.tpl.php");

$page->set("page_content", $inner_page->grabTheGoods());

$page->echoToScreen();

