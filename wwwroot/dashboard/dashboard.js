// Dashboard JavaScript for Active Sessions

// Format seconds into human-readable duration (e.g., "5 minutes", "1h 30m")
function formatDuration(seconds) {
	var hours = Math.floor(seconds / 3600);
	var minutes = Math.floor((seconds % 3600) / 60);

	if (hours > 0) {
		return hours + 'h ' + minutes + 'm';
	} else {
		return minutes + ' minute' + (minutes !== 1 ? 's' : '');
	}
}

// Format elapsed time (MM:SS or HH:MM:SS)
function formatElapsed(seconds) {
	var hours = Math.floor(seconds / 3600);
	var minutes = Math.floor((seconds % 3600) / 60);
	var secs = seconds % 60;

	if (hours > 0) {
		return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
	} else {
		return minutes + ':' + String(secs).padStart(2, '0');
	}
}

// Calculate elapsed seconds from start datetime string
function calculateElapsed(startDt) {
	// Parse start time - format: "YYYY-MM-DD HH:MM:SS"
	var parts = startDt.split(/[- :]/);
	var startTime = new Date(
		parseInt(parts[0]), // year
		parseInt(parts[1]) - 1, // month (0-indexed)
		parseInt(parts[2]), // day
		parseInt(parts[3]), // hour
		parseInt(parts[4]), // minute
		parseInt(parts[5])  // second
	);

	var now = new Date();
	return Math.floor((now - startTime) / 1000);
}

// Create session widget HTML
function createSessionWidget(session) {
	var elapsedSec = calculateElapsed(session.start_local_dt);
	var isBonus = elapsedSec >= session.intended_sec;
	var statusClass = isBonus ? 'bonus' : 'countdown';
	var statusText = isBonus ? '🟢 Bonus Time' : '🟡 Counting Down';

	// Build the URL - use session_key if available, otherwise go to /mg/
	var sessionUrl = session.session_key ? '/mg/' + session.session_key : '/mg/';

	return $('<a>', {
		'href': sessionUrl,
		'class': 'session-widget ' + statusClass,
		'html': '<div class="activity-name">' + session.activity_name + '</div>' +
				'<div class="intended-time">Intended: ' + formatDuration(session.intended_sec) + '</div>' +
				'<div class="elapsed-time" data-start="' + session.start_local_dt + '">' +
				'  Elapsed: <span class="elapsed-value">' + formatElapsed(elapsedSec) + '</span>' +
				'</div>' +
				'<div class="status-indicator">' + statusText + '</div>'
	});
}

// Render active sessions
function renderActiveSessions(sessions) {
	var container = $('#active-sessions');
	container.empty();

	if (sessions.length === 0) {
		$('.empty-state').show();
		return;
	}

	$('.empty-state').hide();

	sessions.forEach(function(session) {
		var widget = createSessionWidget(session);
		container.append(widget);
	});

	// Start live elapsed time updates
	startElapsedTimeUpdates();
}

// Show empty state
function showEmptyState() {
	$('#active-sessions').empty();
	$('.empty-state').show();
}

// Update elapsed times every second
var updateInterval = null;
function startElapsedTimeUpdates() {
	// Clear any existing interval
	if (updateInterval) {
		clearInterval(updateInterval);
	}

	updateInterval = setInterval(function() {
		$('.elapsed-time').each(function() {
			var startDt = $(this).data('start');
			var elapsedSec = calculateElapsed(startDt);
			$(this).find('.elapsed-value').text(formatElapsed(elapsedSec));

			// Update status if we crossed into bonus time
			var widget = $(this).closest('.session-widget');
			var intendedText = widget.find('.intended-time').text();
			// Extract intended seconds from text (rough parsing)
			// This is a bit hacky but works for our format
			if (widget.hasClass('countdown')) {
				// Check if we should switch to bonus
				var intendedMatch = intendedText.match(/(\d+)h\s+(\d+)m|(\d+)\s+minute/);
				var intendedSec;
				if (intendedMatch) {
					if (intendedMatch[1]) {
						// Hours format
						intendedSec = parseInt(intendedMatch[1]) * 3600 + parseInt(intendedMatch[2]) * 60;
					} else {
						// Minutes only
						intendedSec = parseInt(intendedMatch[3]) * 60;
					}

					if (elapsedSec >= intendedSec) {
						widget.removeClass('countdown').addClass('bonus');
						widget.find('.status-indicator').text('🟢 Bonus Time');
					}
				}
			}
		});
	}, 1000);
}

// Load active sessions from API
function loadActiveSessions() {
	$.get('/api/list-active-sessions.php', function(response) {
		if (response.success) {
			renderActiveSessions(response.active_sessions);
		} else {
			console.error('Failed to load active sessions:', response.error);
			showEmptyState();
		}
	}).fail(function(xhr) {
		console.error('API error:', xhr);
		if (xhr.status === 401) {
			// Not logged in - shouldn't happen on dashboard, but handle gracefully
			$('#active-sessions').html('<p>Please log in to view your sessions</p>');
		} else {
			showEmptyState();
		}
	});
}

// Refresh sessions every 30 seconds
function startAutoRefresh() {
	setInterval(function() {
		loadActiveSessions();
	}, 30000); // 30 seconds
}

// Initialize dashboard on page load
$(document).ready(function() {
	loadActiveSessions();
	startAutoRefresh();
});
