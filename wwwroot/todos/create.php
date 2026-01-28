<?php
/**
 * Create Todo Page
 * Handles display and submission of the create todo form
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Authentication Check
if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$is_admin = $is_logged_in->isAdmin();
$user_role = $is_logged_in->getUserRole();
$is_pro = ($user_role === 'pro'); // Use loose check or verify role name logic. Assuming 'pro'.

// Initialize PDO
$pdo = \Database\Base::getPDO($config);

$todoHelper = new \ActivityTracking\Todo($pdo);
$activityHelper = new \ActivityTracking\Activity($pdo);

// Handle POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');

    if (empty($title)) {
        $error = "Title is required";
    } else {
        $data = [
            'user_id' => $user_id,
            'title' => $title,
            'description' => trim($_POST['description'] ?? ''),
            'is_timer' => isset($_POST['is_timer']) ? 1 : 0,
            'is_counter' => isset($_POST['is_counter']) ? 1 : 0,
            'target_count' => (int)($_POST['target_count'] ?? 1),
            'target_duration_seconds' => !empty($_POST['target_duration_seconds']) ? (int)$_POST['target_duration_seconds'] : null,
            'activity_id' => !empty($_POST['activity_id']) ? (int)$_POST['activity_id'] : null,
            'do_time' => !empty($_POST['do_time']) ? $_POST['do_time'] : null,
            'due_date' => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
            'is_active' => 1
        ];

        // Handle Days of Week (Recurrence)
        if (!empty($_POST['do_days']) && is_array($_POST['do_days'])) {
            $data['do_days'] = implode(',', $_POST['do_days']);
        }

        // Handle Dates of Month (Recurrence)
        if (!empty($_POST['do_dates'])) {
            // Clean up and validate integers
            $dates = array_map('trim', explode(',', $_POST['do_dates']));
            $clean_dates = [];
            foreach ($dates as $d) {
                if (is_numeric($d) && $d >= 1 && $d <= 31) {
                    $clean_dates[] = (int)$d;
                }
            }
            if (!empty($clean_dates)) {
                $data['do_dates'] = implode(',', $clean_dates);
            }
        }

        // Validation Logic
        if (!empty($data['activity_id']) && empty($data['target_duration_seconds']) && empty($data['is_counter'])) {
            // If activity is linked, we generally want a duration, but maybe not strictly required if it's just a counter link?
            // User requirement: "required if there is an activity_id selected"
            // Let's enforce it if not a counter? Or just enforce duration?
            // "If there is an activity_id, that implies the todo will take time, and therefore should have a duration."
            if (empty($data['target_duration_seconds'])) {
                $error = "Duration is required when an activity is selected.";
            }
        }

        if (empty($error)) {
            $newId = $todoHelper->createTodo($data);
            if ($newId) {
                // Determine redirect message or location
                header("Location: /dashboard/?msg=todo_created");
                exit;
            } else {
                $error = "Failed to create todo.";
            }
        }
    }
}

// Prepare View
$page = new \Template($config);
$page->setTemplate("layout/welcome_base.tpl.php");
$page->set("page_title", "Create New Todo - Meiso Gambare");

$inner_page = new \Template($config);
$inner_page->setTemplate("todos/create.tpl.php");

// Pass data to template
$activities = $activityHelper->getActivitiesForUser($user_id, $is_admin, $is_pro ?? false);
$inner_page->set("activities", $activities);

if (isset($error)) {
    $inner_page->set("error", $error);
}

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
