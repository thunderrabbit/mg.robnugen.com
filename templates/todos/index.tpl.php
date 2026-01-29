<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>My Todos</h1>
        <a href="/todos/create.php" class="btn-new-timer">+ Create New Todo</a>
    </header>

    <div class="card">
        <?php if (empty($todos)): ?>
            <div class="empty-state">
                <p>You haven't created any todos yet.</p>
                <a href="/todos/create.php" class="btn-primary">Create Your First Todo</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="todos-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Goal</th>
                            <th>Schedule</th>
                            <th>Activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todos as $todo): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($todo['title']) ?></strong>
                                <?php if (!empty($todo['description'])): ?>
                                    <div class="todo-desc"><?= htmlspecialchars(substr($todo['description'], 0, 50)) . (strlen($todo['description']) > 50 ? '...' : '') ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($todo['is_timer']): ?><span class="badge badge-timer">Timer</span><?php endif; ?>
                                <?php if ($todo['is_counter']): ?><span class="badge badge-counter">Counter</span><?php endif; ?>
                                <?php if (!$todo['is_timer'] && !$todo['is_counter']): ?><span class="badge badge-default">Check</span><?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if ($todo['is_timer'] && $todo['target_duration_seconds']) {
                                    echo floor($todo['target_duration_seconds'] / 60) . ' mins';
                                } elseif ($todo['is_counter']) {
                                    echo $todo['target_count'] . 'x';
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $schedule = [];
                                if ($todo['do_days']) $schedule[] = $todo['do_days'];
                                if ($todo['do_dates']) $schedule[] = 'Dates: ' . $todo['do_dates'];
                                if ($todo['do_time']) $schedule[] = 'At: ' . date('g:ia', strtotime($todo['do_time']));
                                if ($todo['due_date']) $schedule[] = 'Due: ' . $todo['due_date'];

                                echo !empty($schedule) ? implode('<br>', $schedule) : 'Anytime';
                                ?>
                            </td>
                            <td>
                                <?= $todo['activity_name'] ? htmlspecialchars($todo['activity_name']) : '-' ?>
                            </td>
                            <td>
                                <!-- Placeholder for Edit/Delete -->
                                <button class="btn-sm btn-disabled" disabled>Edit</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .card {
        background: var(--bg-card);
        padding: 2rem;
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-md);
    }
    .todos-table {
        width: 100%;
        border-collapse: collapse;
    }
    .todos-table th, .todos-table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
    }
    .todos-table th {
        font-weight: 600;
        color: var(--text-muted);
    }
    .todo-desc {
        font-size: 0.85rem;
        color: var(--text-muted);
    }
    .badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
        background: var(--bg-muted);
        color: var(--text-primary);
    }
    .badge-timer { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
    .badge-counter { background: rgba(16, 185, 129, 0.1); color: #10b981; }
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.85rem;
        border-radius: 4px;
        border: 1px solid var(--border-color);
        background: transparent;
        color: var(--text-muted);
        cursor: not-allowed;
    }
    .empty-state {
        text-align: center;
        padding: 3rem;
        color: var(--text-muted);
    }
</style>
