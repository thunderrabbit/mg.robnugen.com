<?php

/**
 * API Endpoint: List Today's Todos
 * Returns todos for the current day with completion status
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

header('Content-Type: application/json');

if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    // Get timezone from request or default to server timezone
    $timezone = $_GET['timezone'] ?? date_default_timezone_get();

    // Create DateTime in user's timezone to get correct day of week
    $tz = new \DateTimeZone($timezone);
    $now = new \DateTime('now', $tz);
    // Day boundary offset: treat midnight-2:59am as the previous day
    $logical = (clone $now)->modify('-3 hours');
    $dayOfWeek = $logical->format('D');
    $today = $logical->format('Y-m-d');
    $dayOfMonth = (int)$logical->format('j');

    $todoHelper = new \ActivityTracking\Todo($pdo);
    $todos = $todoHelper->getTodaysTodos($user_id, $dayOfWeek, $dayOfMonth, $today);

    // Add completion info to each todo and filter out old completed items
    $oneMinuteAgo = (clone $now)->modify('-1 minute');
    $filteredTodos = [];

    foreach ($todos as &$todo) {
        // Check if this is a recurring todo (has days or dates set)
        $isRecurring = !empty($todo['do_days']) || !empty($todo['do_dates']) || !empty($todo['do_every_n_days']);

        // For recurring items, we only care about completion TODAY
        // For one-time items, we care if it's completed AT ALL

        $completions = $todoHelper->getCompletionsForDate($todo['todo_id'], $today);
        $todo['completed_count'] = count($completions);
        $todo['completions'] = $completions;

        // Calculate which nth values are completed (always relevant for today's view)
        $completed_nths = array_column($completions, 'nth');
        $todo['completed_nths'] = array_map('intval', $completed_nths);

        $targetCount = (int)($todo['target_count'] ?? 1);

        if (!$isRecurring && !empty($todo['due_date'])) {
            // ONE-TIME TODO Logic

            // If completed today, we already have that info in $completions
            $completedToday = !empty($completions) && count($completions) >= $targetCount;

            if ($completedToday) {
                // It was finished today. Keep it visible (marked complete)
                // Optionally check the 1-minute rule if we want it to disappear fast,
                // but user requested "keep them briefly visible"
                 $lastCompletion = end($completions);
                 $lastCompletedAt = new \DateTime($lastCompletion['date_logged'], $tz);

                 // If completed very recently (e.g. today), we keep it.
                 // The existing code below handles the "1 minute ago" logic for 'fully completed' items,
                 // effectively hiding them. We might want to respect that logic here too.
            } else {
                 // Not completed *today*. But was it completed *yesterday*?
                 $overallStatus = $todoHelper->getCompletionStatus($todo['todo_id']);
                 $totalCompleted = $overallStatus['count'];

                if ($totalCompleted >= $targetCount) {
                    // It is fully completed overall.
                    // And since it wasn't valid "today" (otherwise captured above),
                    // it must have been completed in the past.
                    // HIDE IT.
                    continue;
                }
                 // If incomplete overall, keep it (it's overdue).
            }
        }

        // --- Days-Between: compute next_due_date ---
        if (!empty($todo['do_every_n_days'])) {
            $logStmt = $pdo->prepare(
                'SELECT MAX(DATE(date_logged)) FROM todo_logs WHERE todo_id = ?'
            );
            $logStmt->execute([$todo['todo_id']]);
            $lastDone = $logStmt->fetchColumn();

            if ($lastDone) {
                $next = new \DateTime($lastDone, $tz);
                $next->modify('+' . (int) $todo['do_every_n_days'] . ' days');
                $todo['next_due_date'] = $next->format('Y-m-d');
                $todo['days_until_due'] = (int) $now->diff($next)->format('%r%a');
            } else {
                // Never completed: start recurring from due_date, not today
                $start = (!empty($todo['due_date']))
                    ? (new \DateTime($todo['due_date'], $tz))->format('Y-m-d')
                    : $today;
                if ($start > $today) {
                    $todo['next_due_date'] = $start;
                    $todo['days_until_due'] = (int) $now->diff(new \DateTime($start, $tz))->format('%r%a');
                } else {
                    $todo['next_due_date'] = $today;
                    $todo['days_until_due'] = 0;
                }
            }
        }

        // --- Existing "Hide if completed > 1 min ago" Logic (Applies to both types) ---
        // This handles removing items from the list after they are "done" for the day.

        $isFullyCompletedForToday = $todo['completed_count'] >= $targetCount;

        if ($isFullyCompletedForToday && !empty($completions)) {
            // Get the most recent completion timestamp
            $lastCompletion = end($completions);
            $lastCompletedAt = new \DateTime($lastCompletion['date_logged'], $tz);

            // Skip if completed more than 1 minute ago
            if ($lastCompletedAt < $oneMinuteAgo) {
                continue;
            }
        }

        // --- Dynamic Start Time Calculation ---
        // If this is a recurring item (target_count > 1) with a set start time,
        // adjust the displayed start time based on completions.
        if (
            $targetCount > 1 &&
            !empty($todo['do_time']) &&
            $todo['completed_count'] < $targetCount
        ) {
            // Deadline is hardcoded to 22:00:00 (10 PM)
            $deadlineStr = $today . ' 22:00:00';
            $deadline = new \DateTime($deadlineStr, $tz);

            // Parse original start time
            $originalTimeStr = $today . ' ' . $todo['do_time'];
            $originalTime = new \DateTime($originalTimeStr, $tz);

            // Calculate total window in seconds
            $totalWindow = $deadline->getTimestamp() - $originalTime->getTimestamp();

            // Only proceed if we have a valid positive window
            if ($totalWindow > 0) {
                // Interval per item
                $interval = $totalWindow / $targetCount;

                // Adjust time: Original + (Completed * Interval)
                $adjustmentSeconds = $todo['completed_count'] * $interval;
                $adjustedTimeTimestamp = $originalTime->getTimestamp() + $adjustmentSeconds;

                // Ensure we don't go past deadline (though logic validates this)
                if ($adjustedTimeTimestamp > $deadline->getTimestamp()) {
                    $adjustedTimeTimestamp = $deadline->getTimestamp();
                }

                $adjustedTime = new \DateTime('@' . $adjustedTimeTimestamp);
                $adjustedTime->setTimezone($tz); // timestamp loses timezone

                // Update the exposed do_time
                $todo['do_time'] = $adjustedTime->format('H:i:s');
                $todo['interval_seconds'] = $interval;
            }
        }
        // --------------------------------------

        $filteredTodos[] = $todo;
    }

    // Sort todos by adjusted time to keep chronological order
    usort($filteredTodos, function ($a, $b) {
        $timeA = $a['do_time'] ?? null;
        $timeB = $b['do_time'] ?? null;

        // Handle NULLs (flexible items) - match MySQL NULLs first behavior?
        // Actually, if we want them to show up where they fit, fine.
        // But usually NULL do_time means "any time", so maybe first is good or last?
        // MySQL ASC puts NULLs first. Let's stick to that.
        if ($timeA === $timeB) {
            // Secondary sort by title
            return strcasecmp($a['title'], $b['title']);
        }

        // If one is null, it comes first (MySQL behavior)
        if ($timeA === null) {
            return -1;
        }
        if ($timeB === null) {
            return 1;
        }

        return strcmp($timeA, $timeB);
    });

    echo json_encode([
        'success' => true,
        'todos' => $filteredTodos,
        'today' => $today,
        'day_of_week' => $dayOfWeek
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve todos'
    ]);
}
