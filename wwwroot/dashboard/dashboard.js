// Dashboard JavaScript for Active Sessions and Todos

// ===== TODOS SECTION =====

// Create checkbox HTML for a todo
function createTodoCheckboxes(todo) {
	var checkboxes = '';
	var targetCount = parseInt(todo.target_count) || 1;
	var completedNths = todo.completed_nths || [];

	for (var i = 1; i <= targetCount; i++) {
		var isChecked = completedNths.indexOf(i) !== -1;
		var checkedAttr = isChecked ? 'checked' : '';
		var checkedClass = isChecked ? 'checked' : '';
		checkboxes += '<label class="todo-checkbox ' + checkedClass + '">' +
			'<input type="checkbox" ' + checkedAttr + ' data-todo-id="' + todo.todo_id + '" data-nth="' + i + '">' +
			'<span class="checkbox-mark"></span>' +
			(targetCount > 1 ? '<span class="nth-label">' + i + '</span>' : '') +
			'</label>';
	}

	return checkboxes;
}

// Create todo widget HTML
function createTodoWidget(todo) {
	var isTimer = parseInt(todo.is_timer) === 1;
	var hasActivityId = todo.activity_id !== null;
	var targetCount = parseInt(todo.target_count) || 1;
	var completedCount = parseInt(todo.completed_count) || 0;
	var isComplete = completedCount >= targetCount;
	var intervalSeconds = todo.interval_seconds || 0;

	var statusClass = isComplete ? 'complete' : 'incomplete';
	if (isTimer) {
		statusClass += ' has-timer';
	}

	var timeDisplay = todo.do_time ? '<span class="todo-time">' + todo.do_time.substring(0, 5) + '</span>' : '';

	var durationDisplay = '';
	if (isTimer && todo.target_duration_seconds) {
		durationDisplay = '<span class="todo-duration">' + formatDuration(parseInt(todo.target_duration_seconds)) + '</span>';
	}

	var startButton = '';
	if (isTimer && hasActivityId) {
		startButton = '<a href="/mg/?activity_id=' + todo.activity_id +
			(todo.target_duration_seconds ? '&duration=' + todo.target_duration_seconds : '') +
			'&todo_id=' + todo.todo_id + '" class="btn-start-timer">START</a>';
	} else if (isTimer) {
		startButton = '<a href="/mg/?todo_id=' + todo.todo_id +
			(todo.target_duration_seconds ? '&duration=' + todo.target_duration_seconds : '') +
			'" class="btn-start-timer">START</a>';
	}

	var progressText = '';
	if (targetCount > 1) {
		progressText = '<span class="todo-progress">' + completedCount + '/' + targetCount + '</span>';
	}

	var widget = $('<div>', {
		'class': 'todo-widget ' + statusClass,
		'data-todo-id': todo.todo_id,
		'data-interval': intervalSeconds,
		'html': '<div class="todo-header">' +
				timeDisplay +
				'<span class="todo-title">' + todo.title + '</span>' +
				'<a href="/todos/create.php?todo_id=' + todo.todo_id + '" class="todo-edit-icon" title="Edit">✎</a>' +
				durationDisplay +
				progressText +
				'</div>' +
				'<div class="todo-actions">' +
				'<div class="todo-checkboxes">' + createTodoCheckboxes(todo) + '</div>' +
				startButton +
				'</div>'
	});

	return widget;
}

// Initialized Sortable instance
var sortableInstance;

// Render todos
function renderTodos(todos) {
	var container = $('#todos-container');
	container.empty();

	if (todos.length === 0) {
		$('.todos-empty-state').show();
		return;
	}

	$('.todos-empty-state').hide();

	todos.forEach(function(todo) {
		var widget = createTodoWidget(todo);
		container.append(widget);
	});

    // Initialize Sortable
    if (!sortableInstance) {
        var el = document.getElementById('todos-container');
        sortableInstance = Sortable.create(el, {
            animation: 150,
            handle: '.todo-widget', // or maybe a specific handle? For now, whole widget.
            onEnd: function (evt) {
                handleTodoDrop(evt);
            },
            onChange: function(evt) {
                updateTodoVisuals(evt);
            }
        });
    }
}

// Update visuals during drag (on change)
function updateTodoVisuals(evt) {
    var item = $(evt.item);
    var prev = item.prev('.todo-widget');
    var next = item.next('.todo-widget');

    var prevTime = prev.length ? prev.find('.todo-time').text().trim() : null;
    var nextTime = next.length ? next.find('.todo-time').text().trim() : null;

    var newTime = calculateTimeBetween(prevTime, nextTime);

    if (newTime) {
        item.find('.todo-time').text(newTime);
        item.addClass('time-updating'); // Optional styling hook
    } else {
        item.find('.todo-time').empty();
        item.removeClass('time-updating');
    }
}

// Handle Drop Event (Finalize and Save)
function handleTodoDrop(evt) {
    // Reuse visual update logic just to be sure we have the final state
    updateTodoVisuals(evt);

    var item = $(evt.item);
    item.removeClass('time-updating'); // Remove temp style

    var newTime = item.find('.todo-time').text().trim() || null;

    // Send to Server
    var todoId = item.data('todo-id');
    updateTodoTime(todoId, newTime, function(success) {
        if (!success) {
            // Revert on failure (simple reload/resort or undo DOM move)
            alert('Failed to save order. Reloading...');
            loadTodos();
        } else {
            // Flash success?
             item.css('background-color', '#e8f5e9');
             setTimeout(function() { item.css('background-color', ''); }, 500);
        }
    });
}

// Calculate time between two HH:MM strings
function calculateTimeBetween(prevStr, nextStr) {
    // Helper to parse HH:MM to minutes
    function toMins(str) {
        if (!str) return null;
        var p = str.split(':');
        return parseInt(p[0]) * 60 + parseInt(p[1]);
    }

    // Helper to format minutes to HH:MM
    function toStr(mins) {
        var h = Math.floor(mins / 60);
        var m = Math.floor(mins % 60);
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    var prev = toMins(prevStr);
    var next = toMins(nextStr);

    if (prev === null && next === null) {
        // Both untimed? Return null (untimed)
        // Or if dragging into untimed list?
        return null;
    }

    // Dragging into start of list
    if (prev === null) {
        // Only next exists. Substract 30 mins?
        // But what if next is 00:15?
        // Logic: if next is untimed, and prev is null -> untimed.
        // If next is timed (e.g. 09:00), new is 08:30.
        if (next !== null) {
            return toStr(Math.max(0, next - 30));
        }
        return null;
    }

    // Dragging to end of list
    if (next === null) {
        // Only prev exists (and is timed). Add 30 mins.
        return toStr(Math.min(1439, prev + 30));
    }

    // Between two times
    // If times are inverted (e.g. 10:00 -> 09:00), just take average?
    // Sortable allows reordering, but list is usually sorted by time.
    // Use average.
    var diff = next - prev;
    var avg = prev + (diff / 2);

    // Round to nearest minute (floor)
    return toStr(Math.floor(avg));
}

// Ensure loadTodos is defined before this or hoist? loadTodos is defined later.

// Load todos from API
function loadTodos() {
	var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

	$.get('/api/todos/list.php?timezone=' + encodeURIComponent(timezone), function(response) {
		if (response.success) {
			renderTodos(response.todos);
		} else {
			console.error('Failed to load todos:', response.error);
			$('.todos-empty-state').show();
		}
	}).fail(function(xhr) {
		console.error('API error:', xhr);
		$('.todos-empty-state').show();
	});
}

// Handle todo checkbox toggle
function handleTodoCheckbox(checkbox) {
	var $checkbox = $(checkbox);
	var $widget = $checkbox.closest('.todo-widget');
	var todoId = $checkbox.data('todo-id');
	var nth = $checkbox.data('nth');
	var isChecked = $checkbox.is(':checked');
	var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
	var interval = parseFloat($widget.data('interval')) || 0;

	var endpoint = isChecked ? '/api/todos/complete.php' : '/api/todos/uncomplete.php';

	$.ajax({
		url: endpoint,
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({
			todo_id: todoId,
			nth: nth,
			timezone: timezone
		}),
		success: function(response) {
			if (response.success) {
				// Update UI
				var $label = $checkbox.closest('.todo-checkbox');
				if (isChecked) {
					$label.addClass('checked just-checked');
					// Remove animation class after animation completes
					setTimeout(function() {
						$label.removeClass('just-checked');
					}, 300);
				} else {
					$label.removeClass('checked');
				}

				// Update progress counter
				var $progress = $widget.find('.todo-progress');
				if ($progress.length) {
					var parts = $progress.text().split('/');
					var current = parseInt(parts[0]);
					var target = parseInt(parts[1]);
					var newCount = isChecked ? current + 1 : current - 1;
					$progress.text(newCount + '/' + target);

					// Update complete/incomplete status
					if (newCount >= target) {
						$widget.removeClass('incomplete').addClass('complete');
					} else {
						$widget.removeClass('complete').addClass('incomplete');
					}

                    // Adjust Start Time (if interval exists)
                    if (interval > 0) {
                        var $timeSpan = $widget.find('.todo-time');
                        if ($timeSpan.length) {
                            var currentTimeStr = $timeSpan.text(); // HH:MM
                            var parts = currentTimeStr.split(':');
                            if (parts.length === 2) {
                                var date = new Date();
                                date.setHours(parseInt(parts[0]));
                                date.setMinutes(parseInt(parts[1]));
                                date.setSeconds(0);

                                // Add or subtract interval
                                var change = isChecked ? interval : -interval;
                                date.setSeconds(date.getSeconds() + change);

                                var newHours = String(date.getHours()).padStart(2, '0');
                                var newMinutes = String(date.getMinutes()).padStart(2, '0');
                                $timeSpan.text(newHours + ':' + newMinutes);

                                // Re-sort the list to reflect new time
                                resortTodos();
                            }
                        }
                    }

				} else {
					// Single checkbox todo
					if (isChecked) {
						$widget.removeClass('incomplete').addClass('complete');
					} else {
						$widget.removeClass('complete').addClass('incomplete');
					}
				}
			} else {
				// Revert checkbox state
				$checkbox.prop('checked', !isChecked);
				alert('Failed to update: ' + (response.error || 'Unknown error'));
			}
		},
		error: function(xhr) {
			// Revert checkbox state
			$checkbox.prop('checked', !isChecked);
			var error = xhr.responseJSON ? xhr.responseJSON.error : 'Failed to update todo';
			alert(error);
		}
	});
}

// Re-sort todos based on time and title
function resortTodos() {
    var $container = $('#todos-container');
    var $todos = $container.children('.todo-widget');

    $todos.sort(function(a, b) {
        var timeA = $(a).find('.todo-time').text().trim();
        var timeB = $(b).find('.todo-time').text().trim();
        var titleA = $(a).find('.todo-title').text().trim().toLowerCase();
        var titleB = $(b).find('.todo-title').text().trim().toLowerCase();

        // Empty times come last (or first? consistent with PHP sort which puts NULLs first)
        // In PHP we did NULLs first.
        // Let's treat empty string as "null".
        if (!timeA && !timeB) return titleA.localeCompare(titleB);
        if (!timeA) return -1;
        if (!timeB) return 1;

        if (timeA === timeB) {
            return titleA.localeCompare(titleB);
        }

        return timeA.localeCompare(timeB);
    });

    $todos.detach().appendTo($container);
}

// Update todo time via API
function updateTodoTime(todoId, newTime, callback) {
    $.ajax({
        url: '/api/todos/update_time.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            todo_id: todoId,
            do_time: newTime
        }),
        success: function(response) {
            if (response.success) {
                callback(true);
            } else {
                console.error('Update failed:', response.error);
                callback(false);
            }
        },
        error: function(xhr) {
             console.error('API error:', xhr);
             callback(false);
        }
    });
}


// Format seconds into human-readable duration (e.g., "5 minutes", "1h 30m", "2d 5h")
function formatDuration(seconds) {
	var days = Math.floor(seconds / 86400);
	var hours = Math.floor((seconds % 86400) / 3600);
	var minutes = Math.floor((seconds % 3600) / 60);

	if (days > 0) {
		return days + 'd ' + hours + 'h';
	} else if (hours > 0) {
		return hours + 'h ' + minutes + 'm';
	} else {
		return minutes + ' minute' + (minutes !== 1 ? 's' : '');
	}
}

// Format elapsed time (MM:SS, HH:MM:SS, or D days HH:MM:SS)
function formatElapsed(seconds) {
	var days = Math.floor(seconds / 86400);
	var hours = Math.floor((seconds % 86400) / 3600);
	var minutes = Math.floor((seconds % 3600) / 60);
	var secs = seconds % 60;

	if (days > 0) {
		// Show days if more than 24 hours
		return days + 'd ' + hours + ':' + String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
	} else if (hours > 0) {
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

	// Build delete button data attribute
	var deleteData = session.session_key ? 'data-session-key="' + session.session_key + '"' : 'data-ak-id="' + session.ak_id + '"';

	var widget = $('<div>', {
		'class': 'session-widget-container',
		'html': '<a href="' + sessionUrl + '" class="session-widget ' + statusClass + '">' +
				'<div class="activity-name">' + session.activity_name + '</div>' +
				'<div class="intended-time">Intended: ' + formatDuration(session.intended_sec) + '</div>' +
				'<div class="elapsed-time" data-start="' + session.start_local_dt + '">' +
				'  Elapsed: <span class="elapsed-value">' + formatElapsed(elapsedSec) + '</span>' +
				'</div>' +
				'<div class="status-indicator">' + statusText + '</div>' +
				'</a>' +
				'<button class="delete-session-btn" ' + deleteData + ' title="Delete session">✕</button>'
	});

	return widget;
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

// ===== COMPLETED SESSIONS =====

var currentOffset = 0;
var completedLimit = 10;

// Format date for completed session display
function formatCompletedDate(utcDateString) {
	// Parse UTC datetime - format: "YYYY-MM-DD HH:MM:SS"
	var parts = utcDateString.split(/[- :]/);
	var date = new Date(Date.UTC(
		parseInt(parts[0]), // year
		parseInt(parts[1]) - 1, // month (0-indexed)
		parseInt(parts[2]), // day
		parseInt(parts[3]), // hour
		parseInt(parts[4]), // minute
		parseInt(parts[5])  // second
	));

	// Format as local date/time
	var options = {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
		hour12: true
	};
	return date.toLocaleString('en-US', options);
}

// Create completed session widget HTML
function createCompletedSessionWidget(session) {
	var bonusDisplay;
	if (session.bonus_sec > 60) {
		bonusDisplay = '<span class="bonus-positive">inc. ' + formatDuration(session.bonus_sec) + ' bonus</span>';
	} else if (session.bonus_sec < 0) {
		bonusDisplay = '<span class="bonus-negative">Stopped early</span>';
	} else {
		bonusDisplay = '<span class="bonus-none">Exactly on time</span>';
	}

	// Build delete button data attribute
	var deleteData = session.session_key ? 'data-session-key="' + session.session_key + '"' : 'data-ak-id="' + session.ak_id + '"';

	// Build the URL - use session_key if available, otherwise skip (no link)
	if (session.session_key) {
		var sessionUrl = '/mg/' + session.session_key;
		return $('<div>', {
			'class': 'session-widget-container',
			'html': '<a href="' + sessionUrl + '" class="session-widget completed">' +
					'<div class="activity-name">✅ ' + session.activity_name + '</div>' +
					'<div class="completion-date">' + formatCompletedDate(session.updated_at_utc) + '</div>' +
					'<div class="duration">' +
					'  Duration: ' + formatDuration(session.actual_sec) + ' ' + bonusDisplay +
					'</div>' +
					'</a>' +
					'<button class="delete-session-btn" ' + deleteData + ' title="Delete session">✕</button>'
		});
	} else {
		// No session key - show as non-clickable div
		return $('<div>', {
			'class': 'session-widget-container',
			'html': '<div class="session-widget completed no-link">' +
					'<div class="activity-name">✅ ' + session.activity_name + '</div>' +
					'<div class="completion-date">' + formatCompletedDate(session.updated_at_utc) + '</div>' +
					'<div class="duration">' +
					'  Duration: ' + formatDuration(session.actual_sec) + ' ' + bonusDisplay +
					'</div>' +
					'</div>' +
					'<button class="delete-session-btn" ' + deleteData + ' title="Delete session">✕</button>'
		});
	}
}

// Render completed sessions
function renderCompletedSessions(sessions, isLoadMore) {
	var container = $('#completed-sessions');

	if (!isLoadMore) {
		container.empty();
	} else {
		// Remove loading indicator if exists
		container.find('.loading').remove();
	}

	if (sessions.length === 0 && !isLoadMore) {
		$('.completed-empty-state').show();
		$('#load-more-sessions').hide();
		return;
	}

	$('.completed-empty-state').hide();

	sessions.forEach(function(session) {
		var widget = createCompletedSessionWidget(session);
		container.append(widget);
	});
}

// Load completed sessions from API
function loadCompletedSessions(isLoadMore) {
	if (!isLoadMore) {
		currentOffset = 0;
	}

	$.get('/api/list-completed-sessions.php?limit=' + completedLimit + '&offset=' + currentOffset, function(response) {
		if (response.success) {
			renderCompletedSessions(response.completed_sessions, isLoadMore);

			// Update offset for next load
			currentOffset += response.completed_sessions.length;

			// Show/hide "Load More" button
			if (response.has_more) {
				$('#load-more-sessions').show();
			} else {
				$('#load-more-sessions').hide();
			}
		} else {
			console.error('Failed to load completed sessions:', response.error);
			if (!isLoadMore) {
				$('.completed-empty-state').show();
			}
		}
	}).fail(function(xhr) {
		console.error('API error:', xhr);
		if (!isLoadMore) {
			$('.completed-empty-state').show();
		}
	});
}

// Delete a session
function deleteSession(button) {
	var sessionKey = $(button).data('session-key');
	var akId = $(button).data('ak-id');

	if (!confirm('Delete this activity session? This cannot be undone.')) {
		return;
	}

	var data = {};
	if (sessionKey) {
		data.session_key = sessionKey;
	} else {
		data.ak_id = akId;
	}

	$.ajax({
		url: '/api/delete-activity-session.php',
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify(data),
		success: function(response) {
			if (response.success) {
				// Remove the widget container from DOM
				$(button).closest('.session-widget-container').fadeOut(300, function() {
					$(this).remove();
				});
			} else {
				alert('Failed to delete: ' + (response.error || 'Unknown error'));
			}
		},
		error: function(xhr) {
			var error = xhr.responseJSON ? xhr.responseJSON.error : 'Failed to delete session';
			alert(error);
		}
	});
}

// Initialize dashboard on page load
$(document).ready(function() {
	// Load todos first (above timers)
	loadTodos();

	loadActiveSessions();
	startAutoRefresh();

	// Load completed sessions
	loadCompletedSessions(false);

	// Handle "Load More" button click
	$('#load-more-sessions').on('click', function() {
		loadCompletedSessions(true);
	});

	// Handle delete button clicks (delegated for dynamically added elements)
	$(document).on('click', '.delete-session-btn', function(e) {
		e.preventDefault();
		e.stopPropagation();
		deleteSession(this);
	});

	// Handle todo checkbox clicks (delegated for dynamically added elements)
	$(document).on('change', '.todo-checkbox input[type="checkbox"]', function(e) {
		handleTodoCheckbox(this);
	});
});
