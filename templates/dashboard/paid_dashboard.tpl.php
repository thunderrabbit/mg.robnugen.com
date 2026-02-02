<link rel="stylesheet" href="/dashboard/<?= SEMVER ?>/dashboard.css">

<div class="dashboard-container">
    <?php if (isset($msg)): ?>
        <?php
        $msgs = [
            'todo_created' => 'Todo created successfully.',
            'todo_updated' => 'Todo updated successfully.'
        ];
        $text = $msgs[$msg] ?? 'Action completed.';
        ?>
        <div class="alert-success">
            <?= htmlspecialchars($text) ?>
        </div>
    <?php endif; ?>

	<section class="todos-section">
		<header class="dashboard-header">
			<h1>Today's Todos</h1>
            <a href="/todos/create.php" class="btn-new-timer">+ Create New Todo</a>
		</header>
		<div class="todos-grid" id="todos-container">
			<div class="loading">Loading todos...</div>
		</div>
		<div class="todos-empty-state" style="display:none;">
			<p>None</p>
		</div>

		<div class="dashboard-nav">
			<br>
			<a href="/todos/history.php" class="nav-arrow arrow-older" title="View Completed Todos">← Older</a>
			<a href="/todos/upcoming.php" class="nav-arrow arrow-newer" title="View Future Todos">Newer →</a>
		</div>

	</section>

	<header class="dashboard-header">
		<h1>My Active Sessions</h1>
		<a href="/mg/" class="btn-new-timer">+ Start New Timer</a>
	</header>

	<div class="active-sessions-grid" id="active-sessions">
		<!-- Populated by JavaScript -->
		<div class="loading">Loading active sessions...</div>
	</div>

	<div class="empty-state" style="display:none;">
		<p>None</p>
	</div>

	<section class="completed-sessions-section">
		<h2>Recent Completed Sessions</h2>
		<div class="completed-sessions-list" id="completed-sessions">
			<!-- Populated by JavaScript -->
			<div class="loading">Loading completed sessions...</div>
		</div>
		<div class="completed-empty-state" style="display:none;">
			<p>None</p>
		</div>
		<button class="load-more" id="load-more-sessions" style="display:none;">Load More</button>
	</section>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="/dashboard/<?= SEMVER ?>/dashboard.js"></script>
