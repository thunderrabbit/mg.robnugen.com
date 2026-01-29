<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Upcoming Todos</h1>
        <a href="/" class="btn-sm">Back to Dashboard</a>
    </header>

    <div class="card">
        <?php if (empty($upcoming)): ?>
            <div class="empty-state">
                <p>No upcoming todos found.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="todos-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Todo</th>
                            <th>Schedule</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcoming as $todo): ?>
                        <tr>
                            <td>
                                <?php
                                    if ($todo['due_date']) {
                                        echo 'Due: ' . date('M j, Y', strtotime($todo['due_date']));
                                    } elseif ($todo['do_dates']) {
                                        echo 'Planned: ' . $todo['do_dates'];
                                    } else {
                                        echo 'Future';
                                    }
                                ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($todo['title']) ?></strong>
                            </td>
                            <td>
                                <?php
                                $schedule = [];
                                if ($todo['do_days']) $schedule[] = $todo['do_days'];
                                if ($todo['do_time']) $schedule[] = date('g:ia', strtotime($todo['do_time']));
                                echo implode(', ', $schedule);
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <div class="dashboard-nav" style="justify-content: center; gap: 20px;">
        <!-- LEFT: Sooner (Prev page or Dashboard) -->
        <?php if ($current_page > 1): ?>
            <a href="/todos/upcoming.php?page=<?= $current_page - 1 ?>" class="nav-arrow">← Sooner</a>
        <?php else: ?>
            <a href="/" class="nav-arrow">← Dashboard</a>
        <?php endif; ?>

        <!-- RIGHT: Later (Next page) -->
        <?php if ($has_more): ?>
            <a href="/todos/upcoming.php?page=<?= $current_page + 1 ?>" class="nav-arrow">Later →</a>
        <?php else: ?>
            <span class="nav-arrow disabled">Later →</span>
        <?php endif; ?>
    </div>
</div>

<style>
    .nav-arrow.disabled {
        opacity: 0.3;
        cursor: default;
    }
</style>
