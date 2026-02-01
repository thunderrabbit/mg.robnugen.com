<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Completed Todos</h1>
        <a href="/" class="btn-sm">Back to Dashboard</a>
    </header>

    <div class="card">
        <?php if (empty($history)): ?>
            <div class="empty-state">
                <p>No completed history found.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="todos-table">
                    <thead>
                        <tr>
                            <th>Completed At</th>
                            <th>Todo</th>
                            <th>Duration/Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $log): ?>
                        <tr>
                            <td><?= date('M j, Y g:ia', strtotime($log['date_logged'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($log['title']) ?></strong>
                            </td>
                            <td>
                                <?php
                                if ($log['duration_seconds']) {
                                    echo gmdate("H:i:s", $log['duration_seconds']);
                                } else {
                                    echo '-';
                                }
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
    <!-- Pagination -->
    <div class="dashboard-nav" style="justify-content: center; gap: 20px;">
        <style>
            .nav-arrow {
                display: inline-block;
                padding: 10px 20px;
                color: white;
                text-decoration: none;
                border-radius: 5px;
                font-weight: bold;
                margin: 0 10px;
            }
            .arrow-older {
                background-color: #E1AD01; /* Mustard */
                color: black !important;
            }
            .arrow-newer {
                background-color: #FF00FF; /* Fuchsia */
                color: white !important;
            }
            /* Override disabled opacity while keeping color */
            .nav-arrow.disabled {
                opacity: 0.3;
                cursor: default;
            }
        </style>
        <!-- LEFT: Older (Next Page mathematically, but "Back in time") -->
        <?php if ($has_more): ?>
            <a href="/todos/history.php?page=<?= $current_page + 1 ?>" class="nav-arrow arrow-older">← Older</a>
        <?php else: ?>
            <span class="nav-arrow arrow-older disabled">← Older</span>
        <?php endif; ?>

        <!-- RIGHT: Newer (Prev Page mathematically, but "Forward in time") -->
        <?php if ($current_page > 1): ?>
            <a href="/todos/history.php?page=<?= $current_page - 1 ?>" class="nav-arrow arrow-newer">Newer →</a>
        <?php else: ?>
            <!-- If on page 1, Right goes to Dashboard options? User asked: "When clicking Right... show dashboard" -->
            <a href="/" class="nav-arrow arrow-newer">Dashboard →</a>
        <?php endif; ?>
    </div>
</div>

<style>
    .nav-arrow.disabled {
        opacity: 0.3;
        cursor: default;
    }
</style>
