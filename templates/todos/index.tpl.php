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
                        <tr id="todo-row-<?= $todo['todo_id'] ?>">
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
                                if (!empty($todo['is_completed'])) {
                                    echo '<span class="text-success">Done (' . date('Y-m-d', strtotime($todo['completed_at'])) . ')</span>';
                                } else {
                                    $schedule = [];
                                    if (!empty($todo['do_every_n_days'])) $schedule[] = 'Every ' . (int) $todo['do_every_n_days'] . ' days';
                                    if ($todo['do_days']) $schedule[] = $todo['do_days'];
                                    if ($todo['do_dates']) $schedule[] = 'Dates: ' . $todo['do_dates'];
                                    if ($todo['do_time']) $schedule[] = 'At: ' . date('g:ia', strtotime($todo['do_time']));
                                    if ($todo['due_date']) $schedule[] = 'Due: ' . $todo['due_date'];

                                    echo !empty($schedule) ? implode('<br>', $schedule) : 'Anytime';
                                }
                                ?>
                            </td>
                            <td>
                                <?= $todo['activity_name'] ? htmlspecialchars($todo['activity_name']) : '-' ?>
                            </td>
                            <td>
                                <a href="/todos/create.php?todo_id=<?= $todo['todo_id'] ?>" class="btn-sm btn-edit">Edit</a>
                                <a href="/todos/archive.php?todo_id=<?= $todo['todo_id'] ?>"
                                   class="btn-sm btn-delete action-archive"
                                   data-todo-id="<?= $todo['todo_id'] ?>">Archive</a>
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
    .text-success {
        color: #10b981;
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
        text-decoration: none;
        display: inline-block;
    }
    .btn-edit:hover {
        border-color: #3b82f6;
        color: #3b82f6;
        background: rgba(59, 130, 246, 0.05);
    }
    .btn-delete:hover {
        border-color: #ef4444;
        color: #ef4444;
        background: rgba(239, 68, 68, 0.05);
    }
    .btn-disabled {
        cursor: not-allowed;
        opacity: 0.6;
    }
    .empty-state {
        text-align: center;
        padding: 3rem;
        color: var(--text-muted);
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const archiveLinks = document.querySelectorAll('.action-archive');

    archiveLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();

            const todoId = this.dataset.todoId;
            const row = document.getElementById('todo-row-' + todoId);
            const originalDisplay = row.style.display;

            // Optimistically hide the row
            row.style.display = 'none';

            fetch(this.href, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.error || 'Failed to archive');
                }
                // Success - row is already hidden, remove it from DOM to be clean
                row.remove();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to archive todo: ' + error.message);
                // Revert optimistic update
                row.style.display = originalDisplay;
            });
        });
    });
});
</script>
