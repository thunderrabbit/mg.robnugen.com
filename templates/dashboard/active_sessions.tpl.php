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
</div>

<script src="/dashboard/dashboard.js"></script>
