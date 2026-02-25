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

	// Determine sort handle - now always the dedicated handle
    var sortHandle = '<span class="todo-sort-area todo-sort-handle" title="Drag to reorder">⋮⋮</span>';

    	var timeDisplay = '';
	if (todo.do_time) {
		timeDisplay = '<span class="todo-time editable" data-field="do_time" data-value="' + todo.do_time + '">' + todo.do_time.substring(0, 5) + '</span>';
	} else if (todo.due_date) {
		timeDisplay = '<span class="todo-date editable" data-field="due_date" data-value="' + todo.due_date + '">' + formatTodoDate(todo.due_date) + '</span>';
	}

	var durationDisplay = '';
	if (isTimer && todo.target_duration_seconds) {
		durationDisplay = '<span class="todo-duration editable" data-field="target_duration_seconds" data-value="' + todo.target_duration_seconds + '">' + formatDuration(parseInt(todo.target_duration_seconds)) + '</span>';
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

	// Check for highlighting
	var extraClasses = '';
	if (window.highlightIds && window.highlightIds.indexOf(todo.todo_id) !== -1) {
		extraClasses = ' newly-added';
	}

	var widget = $('<div>', {
		'class': 'todo-widget ' + statusClass + extraClasses,
		'data-todo-id': todo.todo_id,
		'data-interval': intervalSeconds,
		'html': '<div class="todo-header">' +
				sortHandle +
				timeDisplay +
				'<span class="todo-title editable" data-field="title">' + todo.title + '</span>' +
				'<a href="/todos/create.php?todo_id=' + todo.todo_id + '" class="todo-edit-icon" title="Edit">✎</a>' +
				durationDisplay +
				progressText +
				'</div>' +
				'<div class="todo-actions">' +
				'<div class="todo-checkboxes">' + createTodoCheckboxes(todo) + '</div>' +
				startButton +
				'</div>'
	});

    // Attach long-press handlers
    attachLongPressHandler(widget.find('.editable'));

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
            handle: '.todo-sort-handle', // Dedicated handle only
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


// Parse duration string (e.g. "1h 30m", "90m", "1.5h") to seconds
function parseDuration(str) {
    if (!str) return 0;
    str = str.toLowerCase().trim();

    var totalSeconds = 0;

    // Check for hours
    var hoursMatch = str.match(/(\d+(?:\.\d+)?)\s*h/);
    if (hoursMatch) {
        totalSeconds += parseFloat(hoursMatch[1]) * 3600;
    }

    // Check for moments/minutes
    var minutesMatch = str.match(/(\d+(?:\.\d+)?)\s*m/);
    if (minutesMatch) {
        totalSeconds += parseFloat(minutesMatch[1]) * 60;
    }

    // Fallback: if just a number, assume minutes
    if (!hoursMatch && !minutesMatch) {
        var num = parseFloat(str);
        if (!isNaN(num)) {
            totalSeconds = num * 60;
        }
    }

    return Math.round(totalSeconds);
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

// Format YYYY-MM-DD to DD-Mon-YYYY
function formatTodoDate(dateStr) {
    if (!dateStr) return '';
    // Ensure we only have the date part in case it's a datetime
    var datePart = dateStr.substring(0, 10);
    var parts = datePart.split('-');
    if (parts.length !== 3) return datePart;

    var year = parts[0];
    var monthIndex = parseInt(parts[1]) - 1;
    var day = parts[2];

    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var monthName = months[monthIndex];

    return day + '-' + monthName + '-' + year;
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
		$('#active-timers-heading').hide();
		return;
	}

	$('#active-timers-heading').show();

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
	$('#active-timers-heading').hide();
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

// ===== QUICKADD MULTIPLE TODOS =====

function toggleQuickAdd() {
    var $container = $('#quickadd-container');
    if ($container.is(':visible')) {
        $container.slideUp();
    } else {
        $container.slideDown();
        $('#quickadd-input').focus();
    }
}

function parseQuickAddLine(line) {
    line = line.trim();
    if (!line) return null;

    var todo = {
        title: line,
        do_time: null,
        due_date: null,
        target_duration_seconds: null
    };

    // 1. Check for Time at start (HH:MM)
    var timeMatch = line.match(/^(\d{1,2}:\d{2})\s*/);
    if (timeMatch) {
        todo.do_time = timeMatch[1]; // Use as is, backend validation will catch weirdness or we can pad hours
        line = line.substring(timeMatch[0].length);
    }

    // 2. Check for Date at start (after time extraction)
    // Supports DD-Mon-YYYY, DD/MM/YYYY, YYYY-MM-DD
    // Simple regex for likely date formats
    var dateMatch = line.match(/^(\d{1,2}-\w{3}-\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})\s*/);
    if (dateMatch) {
        // Need to standardize date format for backend (YYYY-MM-DD)
        // Let's use a helper or Date.parse?
        var dateStr = dateMatch[1];
        var d = new Date(dateStr);
        if (!isNaN(d.getTime())) {
             // Format to YYYY-MM-DD
             var month = '' + (d.getMonth() + 1);
             var day = '' + d.getDate();
             var year = d.getFullYear();

             if (month.length < 2) month = '0' + month;
             if (day.length < 2) day = '0' + day;

             todo.due_date = [year, month, day].join('-');
        }
        line = line.substring(dateMatch[0].length);
    }

    // 3. Check for Duration at end (e.g. (45m), (1h))
    // Look for last occurrence of parens with duration-like content
    var durationMatch = line.match(/\(([^)]+)\)$/);
    if (durationMatch) {
        var potentialDuration = durationMatch[1];
        var seconds = parseDuration(potentialDuration);
        if (seconds > 0) {
            todo.target_duration_seconds = seconds;
            // Remove from line
            line = line.substring(0, line.lastIndexOf('(')).trim();
        }
    }

    todo.title = line.trim();
    return todo;
}


function saveQuickAddTodos() {
    var text = $('#quickadd-input').val();
    if (!text.trim()) return;

    var lines = text.split('\n');
    var todos = [];

    lines.forEach(function(line) {
        var todo = parseQuickAddLine(line);
        if (todo) {
            todos.push(todo);
        }
    });

    if (todos.length === 0) return;

    // Disable button
    $('#btn-quickadd-save').prop('disabled', true).text('Saving...');

    $.ajax({
        url: '/api/todos/create_batch.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ todos: todos }),
        success: function(response) {
            if (response.success) {
                // Clear input and hide
                $('#quickadd-input').val('');
                $('#quickadd-container').slideUp();

                // Reload todos
                // We want to highlight the new ones.
                // Pass IDs to render logic?
                // renderTodos clears the list, so we might need a way to pass "highlight IDs" to it.
                // Or just add a global var or param.

                window.highlightIds = response.created_ids || [];
                loadTodos(); // This calls renderTodos

            } else {
                alert('Failed to save todos: ' + (response.error || 'Unknown error'));
            }
        },
        error: function(xhr) {
             var error = xhr.responseJSON ? xhr.responseJSON.error : 'Connection failed';
             alert('Failed to save todos: ' + error);
        },
        complete: function() {
            $('#btn-quickadd-save').prop('disabled', false).text('Save Todos');
        }
    });
}


$(document).ready(function() {
    // Event listeners for Quickadd
    $('#btn-quickadd-toggle').on('click', toggleQuickAdd);
    $('#btn-quickadd-cancel').on('click', toggleQuickAdd);
    $('#btn-quickadd-save').on('click', saveQuickAddTodos);
});

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
// Long press handler for inline editing
function attachLongPressHandler($elements) {
    if (!$elements || !$elements.length) return;

    var pressTimer;
    var isLongPress = false;

    // Mouse events
    $elements.on('mousedown', function(e) {
        if (e.which !== 1) return; // Only left click
        isLongPress = false;
        var $el = $(this);
        pressTimer = setTimeout(function() {
            isLongPress = true;
            $el.css('background-color', '');
            makeEditable($el);
        }, 500);
    }).on('mouseup mouseleave', function() {
        clearTimeout(pressTimer);
    });

    // Touch events for mobile
    $elements.on('touchstart', function(e) {
        isLongPress = false;
        var $el = $(this);
        pressTimer = setTimeout(function() {
            isLongPress = true;
            $el.css('background-color', ''); // Clear highlight before editing
            makeEditable($el);
        }, 500);
    }).on('touchend touchcancel', function() {
        clearTimeout(pressTimer);
    });
}

// Transform element into inline editor
function makeEditable($el) {
    if ($el.find('input').length > 0) return; // Already editing

    var field = $el.data('field');
    var originalValue = $el.data('value') || $el.text();
    var width = $el.outerWidth() + 20;

    // Determine input type
    var inputType = 'text';
    var displayValue = originalValue;

    if (field === 'do_time') inputType = 'time';
    if (field === 'due_date') inputType = 'date';
    if (field === 'target_duration_seconds') {
        // Convert seconds to "1h 30m" format for editing
        displayValue = formatDuration(parseInt(originalValue));
    }

    // Create input
    var $input = $('<input>', {
        type: inputType,
        value: displayValue,
        'class': 'inline-editor',
        css: {
            width: Math.max(width, 80) + 'px', // Min width for usability
            padding: '2px 4px',
            border: '1px solid #2196F3',
            borderRadius: '4px',
            fontSize: 'inherit',
            fontFamily: 'inherit'
        }
    });

    // Save original content to restore on cancel
    $el.data('original-content', $el.html());
    $el.html($input);
    $input.focus();

    // Select all text + allow immediate typing
    // setTimeout needed for some browsers to handle focus -> select correctly
    setTimeout(function() { $input.select(); }, 10);

    // Event handlers
    $input.on('blur', function() {
        cancelEdit($el);
    });

    $input.on('keydown', function(e) {
        if (e.key === 'Enter') {
            saveEdit($el, $(this).val());
        } else if (e.key === 'Escape') {
            cancelEdit($el);
        }
    });
}

// Cancel inline edit
function cancelEdit($el) {
    // Restore original content
    if ($el.data('original-content')) {
        $el.html($el.data('original-content'));
    }
}

// Save inline edit (Mock for now)
// Save inline edit
function saveEdit($el, newValue) {
    var field = $el.data('field');
    var todoId = $el.closest('.todo-widget').data('todo-id');
    var originalValue = $el.data('value'); // For reverting
    var originalText = $el.data('original-content'); // For reverting text specifically

    // Logic for value conversion
    var apiValue = newValue;
    if (field === 'target_duration_seconds') {
        apiValue = parseDuration(newValue);
    }

    // 1. Optimistic Update
    var displayValue = newValue;

    // Formatting logic for display
    if (field === 'do_time') {
        // Input is HH:MM, sometimes HH:MM:SS. Strip seconds for display.
        if (displayValue && displayValue.length > 5) {
            displayValue = displayValue.substring(0, 5);
        }
    } else if (field === 'due_date') {
        // Input is YYYY-MM-DD. Format to DD-Mon-YYYY.
        displayValue = formatTodoDate(newValue);
    } else if (field === 'target_duration_seconds') {
        // Format seconds back to pretty string
        displayValue = formatDuration(apiValue);
    }

    // Update DOM immediately
    $el.text(displayValue);
    // Update data-value
    if (field === 'target_duration_seconds') {
        $el.data('value', apiValue);
    } else {
        $el.data('value', newValue);
    }

    // Flash success styling immediately
    $el.css('background-color', '#e8f5e9');

    // 2. Send to Server
    $.ajax({
        url: '/api/todos/update_field.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            todo_id: todoId,
            field: field,
            value: apiValue
        }),
        success: function(response) {
            if (response.success) {
                // Success confirmed. Remove flash after delay.
                setTimeout(function() { $el.css('background-color', ''); }, 500);
            } else {
                // Server error
                revertEdit($el, originalText, originalValue);
                alert('Failed to save: ' + (response.error || 'Unknown error'));
            }
        },
        error: function(xhr) {
            // Network/Server error
            revertEdit($el, originalText, originalValue);
            var error = xhr.responseJSON ? xhr.responseJSON.error : 'Connection failed';
            alert('Failed to save: ' + error);
        }
    });

}

function revertEdit($el, originalText, originalValue) {
    // Restore original content (which was html/text)
    if (originalText !== undefined) {
        $el.html(originalText);
    } else if ($el.data('original-content')) {
        $el.html($el.data('original-content'));
    }

    // Also revert data-value
    if (originalValue !== undefined) {
        $el.data('value', originalValue);
    }

    // Flash error
    $el.css('background-color', '#ffebee');

	setTimeout(function() { $el.css('background-color', ''); }, 500);
}

// Create completed session widget HTML (Now handles Fully Completed Todos)
function createCompletedSessionWidget(session) {
    // Determine title: use Todo Title. Fallback to activity name if available.
    var title = session.title || session.activity_name || 'Untitled Todo';
    var activityName = session.activity_name ? ' (' + session.activity_name + ')' : '';

    var durationDisplay = '';
    // Only show duration if it was a timed todo (is_timer=1 and duration > 0) or has actual_sec from session
    if (session.is_timer == 1 || session.actual_sec > 0) {
        var actualSec = session.actual_sec || session.duration_seconds || 0;
        var bonusSec = session.bonus_sec || 0;

        var bonusDisplay;
        if (bonusSec > 60) {
            bonusDisplay = '<span class="bonus-positive">inc. ' + formatDuration(bonusSec) + ' bonus</span>';
        } else if (bonusSec < 0) {
            bonusDisplay = '<span class="bonus-negative">Stopped early</span>';
        } else {
             // For todos without session linkage but are timed, we might not have bonus info
             // Just show nothing for bonus if 0
             bonusDisplay = '';
        }

		if(actualSec > 0) {
        durationDisplay = '<span class="duration">' +
					'  Duration: ' + formatDuration(actualSec) + ' ' + bonusDisplay +
					'</span>';
		}
    }

	// Build delete/undo button (removed logic for now as API expects specific ID types, maybe todo_log_id in future?)
    // For now, keep using delete-session-btn if we have session key.
    // If it's a raw todo log without session, we might need a new delete endpoint or update delete-activity-session
    // The current deleteSession function uses session_key or ak_id.
    // Our new API returns ak_id (nullable) and log_id.
    // If ak_id exists, we can use it. If not, we can't easily delete via existing API.
    // Let's only show delete if ak_id exists for now to be safe, or if we update deleteSession.

	var deleteBtn = '';
    if (session.ak_id) {
         deleteBtn = '<button class="delete-session-btn" data-ak-id="' + session.ak_id + '" title="Delete session">✕</button>';
    }

	// Build the URL - link to todo? or activity?
    // If it has a session key (not in new API yet), we linked to /mg/.
    // New API doesn't return session_key.
    // Let's link to the todo edit page.
    var linkUrl = '/todos/create.php?todo_id=' + session.todo_id;

    // Use date_logged (local time string from DB)
    // We need to parse it carefully or display as is if it's Y-m-d H:i:s
    // formatCompletedDate expects UTC string.
    // Let's write a simple formatter for the local string "YYYY-MM-DD HH:MM:SS"
    var dateDisplay = session.date_logged;
    try {
        // Basic parser for "YYYY-MM-DD HH:MM:SS"
        var parts = session.date_logged.split(/[- :]/);
        var d = new Date(parts[0], parts[1]-1, parts[2], parts[3], parts[4], parts[5]);
        var options = { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
        dateDisplay = d.toLocaleString('en-US', options);
    } catch(e) {}

	return $('<div>', {
		'class': 'session-widget-container',
		'html': '<a href="' + linkUrl + '" class="session-widget completed">' +
                '<div class="completed-task">' +
                '  <span class="completed-check">✅</span> ' +
                '  <span class="completed-title">' + title + '</span>' +
                (activityName ? '<span class="completed-activity-name"><small>' + activityName + '</small></span>' : '') +
                '</div>' +
				'<div class="completion-details">' +
                '  <span class="completion-date">' + dateDisplay + '</span>' +
				  durationDisplay +
                '</div>' +
				'</a>' +
				deleteBtn
	});
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
		$('.completed-sessions-section').hide();
		return;
	}

	$('.completed-sessions-section').show();
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

	$.get('/api/list-fully-completed-todos.php?limit=' + completedLimit + '&offset=' + currentOffset, function(response) {
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
