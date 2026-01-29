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

// Check for Edit Mode
$todo_id = isset($_REQUEST['todo_id']) ? (int)$_REQUEST['todo_id'] : null;
$todo_data = null;
$page_title = "Create New Todo - Meiso Gambare";
$btn_text = "Create Todo";

if ($todo_id) {
    if (!$todoHelper->verifyOwnership($todo_id, $user_id)) {
        // Not authorized or doesn't exist
        header("Location: /todos/create.php?err=not_found");
        exit;
    }
    $todo_data = $todoHelper->getTodo($todo_id);
    if (!$todo_data) {
         header("Location: /todos/create.php?err=not_found");
         exit;
    }
    $page_title = "Edit Todo - Meiso Gambare";
    $btn_text = "Update Todo";

    // Format dates for HTML5 inputs
    if (!empty($todo_data['due_date'])) {
        $todo_data['due_date'] = date('Y-m-d', strtotime($todo_data['due_date']));
    }
    if (!empty($todo_data['do_time'])) {
        $todo_data['do_time'] = date('H:i', strtotime($todo_data['do_time']));
    }
}

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
        } else {
            $data['do_days'] = null; // Explicitly clear if empty
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
            } else {
                $data['do_dates'] = null;
            }
        } else {
            $data['do_dates'] = null;
        }

        // Validation Logic
        if (!empty($data['activity_id']) && empty($data['target_duration_seconds']) && empty($data['is_counter'])) {
            if (empty($data['target_duration_seconds'])) {
                $error = "Duration is required when an activity is selected.";
            }
        }

        if (empty($error)) {
            if ($todo_id) {
                // Update
                if ($todoHelper->updateTodo($todo_id, $data)) {
                     header("Location: /?msg=todo_updated");
                     exit;
                } else {
                    $error = "Failed to update todo.";
                }
            } else {
                // Create
                $newId = $todoHelper->createTodo($data);
                if ($newId) {
                    header("Location: /?msg=todo_created");
                    exit;
                } else {
                    $error = "Failed to create todo.";
                }
            }
        }
    }
}

// Prepare View
$page = new \Template($config);
$page->setTemplate("layout/welcome_base.tpl.php");
$page->set("page_title", $page_title);

$inner_page = new \Template($config);
$inner_page->setTemplate("todos/create.tpl.php");

// Pass data to template
$activities = $activityHelper->getActivitiesForUser($user_id, $is_admin, $is_pro ?? false);
$inner_page->set("activities", $activities);
$inner_page->set("todo", $todo_data); // Will be null if creating
$inner_page->set("is_edit", !!$todo_id);
$inner_page->set("btn_text", $btn_text);

if (isset($error)) {
    $inner_page->set("error", $error);
}

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
