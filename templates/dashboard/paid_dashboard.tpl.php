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

	<section class="active-sessions-section">
		<header class="dashboard-header" id="active-sessions-header">
			<h1 id="active-timers-heading" style="display:none;">My Active Timers</h1>
			<a href="/mg/" class="btn-new-timer">+ Start New Timer</a>
		</header>

		<div class="active-sessions-grid" id="active-sessions">
			<!-- Populated by JavaScript -->
		</div>
	</section>

	<section class="todos-section">
		<header class="dashboard-header">
			<h1>Today's Todos</h1>
            <div class="dashboard-actions">
                <a href="/todos/create.php" class="btn-new-timer">+ Create New Todo</a>
                <?php if ($is_logged_in->isAdmin()): ?>
                <button id="btn-quickadd-toggle" class="btn-quickadd">Add Multiple Todos</button>
                <?php endif; ?>
            </div>
		</header>

        <div id="quickadd-container" class="quickadd-container" style="display:none;">
            <textarea id="quickadd-input" rows=9 cols=80 placeholder="Enter multiple todos, one per line.&#10;Time, date, and duration are optional.&#10;Examples:&#10;Buy milk&#10;18:00 Cook dinner (45m)&#10;09:00 15-Feb-2026 Dentist Appointment"></textarea>
            <div class="quickadd-controls">
                <button id="btn-quickadd-save" class="btn-save">Save Todos</button>
                <button id="btn-quickadd-cancel" class="btn-cancel">Cancel</button>
            </div>
            <div id="quickadd-feedback" class="quickadd-feedback"></div>
        </div>
		<div class="todos-grid" id="todos-container">
			<div class="loading">Loading todos...</div>
		</div>
		<div class="todos-empty-state" style="display:none;">
			<p>None</p>
		</div>
	</section>

	<section class="completed-sessions-section" style="display:none;">
		<h2>Completed</h2>
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
