<link rel="stylesheet" href="/dashboard/dashboard.css">

<div class="dashboard-container">
	<header class="dashboard-header">
		<h1>My Active Sessions</h1>
		<a href="/mg/" class="btn-new-timer">+ Start New Timer</a>
	</header>

	<div class="active-sessions-grid" id="active-sessions">
		<!-- Populated by JavaScript -->
		<div class="loading">Loading active sessions...</div>
	</div>

	<div class="empty-state" style="display:none;">
		<p>No active sessions</p>
		<a href="/mg/" class="btn-primary">Start Your First Timer</a>
	</div>

	<section class="completed-sessions-section">
		<h2>Recent Completed Sessions</h2>
		<div class="completed-sessions-list" id="completed-sessions">
			<!-- Populated by JavaScript -->
			<div class="loading">Loading completed sessions...</div>
		</div>
		<div class="completed-empty-state" style="display:none;">
			<p>No completed sessions yet</p>
		</div>
		<button class="load-more" id="load-more-sessions" style="display:none;">Load More</button>
	</section>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="/dashboard/<?= SEMVER ?>/dashboard.js"></script>
