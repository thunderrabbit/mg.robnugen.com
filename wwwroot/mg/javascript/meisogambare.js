var clock;
var reachedGoalTime = false;
var sessionStartTime = null; // Track when session started
var currentAkId = null; // Track current activity session ID
var currentActivityId = 1; // Default to Meditation, will be updated when activities load
var currentSessionKey = null; // Track session key for admin/pro users
var currentTodoId = null; // Track linked todo for auto-completion

var meisoPrefs = MeisoPreferences();
var reveal_duration = meisoPrefs.getRevealDuration();
var hide_duration = meisoPrefs.getHideDuration();
var successBGColor = meisoPrefs.getSuccessBGColor();
var countingColor = meisoPrefs.getCountingColor();

var clickedStartButton = function(e) {
	reachedGoalTime = false;
	changePageColor(countingColor);
	meisoPrefs.setMeditationTime($('#countdown_minutes').val());	// save to local storage for next time
	clock.setTime($('#countdown_minutes').val() * 60);
	clock.setCountdown(true);
	clock.start();
	hideStuffs();

	// Track session start time
	sessionStartTime = new Date();

	// Call API to start activity (if logged in)
	startActivitySession();
}

var clickedStopButton = function(e) {
	clock.stop();
	setSuccessString();
	revealStuffs();

	// Call API to stop activity (if logged in)
	stopActivitySession();
}

// Start activity session via API
var startActivitySession = function() {
	// Get timezone from browser
	var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

	// Format local datetime as YYYY-MM-DD HH:MM:SS
	var now = new Date();
	var localDt = now.getFullYear() + '-' +
		String(now.getMonth() + 1).padStart(2, '0') + '-' +
		String(now.getDate()).padStart(2, '0') + ' ' +
		String(now.getHours()).padStart(2, '0') + ':' +
		String(now.getMinutes()).padStart(2, '0') + ':' +
		String(now.getSeconds()).padStart(2, '0');


	var countdownMinutes = parseInt($('#countdown_minutes').val()) || 5; // Default to 5 if empty
	var intendedSec = countdownMinutes * 60;

	// Don't send API request if intended time is invalid
	if (intendedSec <= 0) {
		console.log('Invalid countdown time, skipping API call');
		return;
	}


	$.ajax({
		url: '/api/start-activity.php',
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({
			activity_id: currentActivityId,
			intended_sec: intendedSec,
			timezone_iana: timezone,
			start_local_dt: localDt
		}),
		success: function(response) {
			if (response.success) {
				currentAkId = response.ak_id;
				console.log('Activity session started:', response.ak_id);

				// If session_key returned (admin/pro users), redirect to unique URL
				if (response.session_key) {
					currentSessionKey = response.session_key;
					console.log('Redirecting to session URL:', response.session_key);
					var redirectUrl = '/mg/' + response.session_key;
					if (currentTodoId) {
						redirectUrl += '?todo_id=' + currentTodoId;
					}
					window.location.href = redirectUrl;
				}
			}
		},
		error: function(xhr) {
			// If 401 (not logged in), that's okay - timer still works
			if (xhr.status === 401) {
				console.log('Not logged in - session not saved');
			} else {
				console.error('Failed to start activity session:', xhr.responseJSON);
			}
		}
	});
}

// Stop activity session via API
var stopActivitySession = function() {
	if (!currentAkId && !sessionStartTime) {
		return; // No session to stop
	}

	// Calculate actual duration
	var now = new Date();
	var actualSec = Math.floor((now - sessionStartTime) / 1000);

	// Calculate bonus time (time beyond countdown)
	var intendedSec = parseInt($('#countdown_minutes').val()) * 60;
	var bonusSec = Math.max(0, actualSec - intendedSec);

	// Prepare data - use session_key if available, otherwise ak_id
	var stopData = {
		actual_sec: actualSec,
		bonus_sec: bonusSec
	};

	if (currentSessionKey) {
		stopData.session_key = currentSessionKey;
	} else {
		stopData.ak_id = currentAkId;
	}

	$.ajax({
		url: '/api/stop-activity.php',
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify(stopData),
		success: function(response) {
			if (response.success) {
				console.log('Activity session stopped successfully');

				// Auto-complete linked todo if present
				if (currentTodoId && currentAkId) {
					console.log('Auto-completing todo (line 136):', currentTodoId);
					autoCompleteTodo(currentTodoId, currentAkId, actualSec);
				} else {
					console.log('No todo or ak_id to auto-complete (line 139)');
					console.log('currentTodoId:', currentTodoId);
					console.log('currentAkId:', currentAkId);
				}

				// Don't clear currentTodoId here, wait for countDownFinished or manual stop
				currentAkId = null;
				sessionStartTime = null;
			}
		},
		error: function(xhr) {
			// If 401 (not logged in), that's okay
			if (xhr.status === 401) {
				console.log('Not logged in - session not saved');
			} else {
				console.error('Failed to stop activity session:', xhr.responseJSON);
			}
			// Clear session data anyway
			currentAkId = null;
			sessionStartTime = null;
		}
	});
}

// Auto-complete a todo when its linked activity session ends
var autoCompleteTodo = function(todoId, akId, durationSec) {
	var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

	$.ajax({
		url: '/api/todos/complete-with-session.php',
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({
			todo_id: todoId,
			ak_id: akId,
			duration_seconds: durationSec,
			timezone: timezone
		}),
		success: function(response) {
			if (response.success) {
				console.log('Todo auto-completed:', todoId);
			} else {
				console.log('Failed to auto-complete todo:', response.error);
			}
		},
		error: function(xhr) {
			console.log('Error auto-completing todo:', xhr.responseJSON);
		}
	});
}

var hideStuffs = function() {
	$('.start').hide(hide_duration);
	$('.duration-field-wrapper').hide(hide_duration);
	$('.share').hide(reveal_duration);
	$('#post_timer_links').hide(hide_duration);
}

var revealStopButton = function() {
	$('.stop').show(reveal_duration);
}

var countDownFinished = function() {
	document.getElementById("audio-bell").play();
	revealStopButton();
	changePageColor(successBGColor);

	// Auto-complete linked todo when countdown finishes (reached goal time)
	if (currentTodoId && currentAkId && sessionStartTime) {
		var intendedSec = parseInt($('#countdown_minutes').val()) * 60;
		console.log('Countdown finished - line 206 - auto-completing todo:', currentTodoId);
		autoCompleteTodo(currentTodoId, currentAkId, intendedSec);
		// Clear todo ID so we don't complete again when Stop is clicked
		currentTodoId = null;
	} else {
		console.log('Countdown finished - line 211 - no todo or ak_id to auto-complete');
		console.log('currentTodoId:', currentTodoId);
		console.log('currentAkId:', currentAkId);
		console.log('sessionStartTime:', sessionStartTime);
	}
}

var revealStuffs = function() {
	$('.stop').hide(reveal_duration);
	$('.share').show(reveal_duration);
	$('.start').show(hide_duration);
	$('.duration-field-wrapper').show(hide_duration);
	$('#post_timer_links').show(reveal_duration);
}

var setSuccessString = function() {
	var intialMinutes = $('#countdown_minutes').val();
	var extraSeconds = clock.getTime().time;
	var extraMinutes = Math.floor(extraSeconds / 60);
	var successString;

	if(extraMinutes) {
		successString = "I meditated for " + intialMinutes + " minutes and " + extraMinutes + " bonus minutes!";
	} else {
		successString = "I meditated for " + intialMinutes + " minutes!";
	}
	// putting string here just for convenience
	// we could unhide this field or send its value to anything we like
	$("#share_success_string").val(successString);
	$("#twitter_link").attr("href","http://twitter.com/share/?text=" + encodeURIComponent(successString));
}

var changePageColor = function(newColor) {
	$('.body').css({backgroundColor:newColor},1000);
}

// Get session key from URL (e.g., /mg/abc123de)
var getSessionKeyFromURL = function() {
	var path = window.location.pathname;
	var match = path.match(/^\/mg\/([a-zA-Z0-9_-]{11})$/);
	return match ? match[1] : null;
}

// Get URL query parameter by name
var getURLParam = function(name) {
	var urlParams = new URLSearchParams(window.location.search);
	return urlParams.get(name);
}

// Apply URL parameters (activity_id, duration, todo_id)
var applyURLParams = function() {
	// Check for todo_id (for auto-completion when session ends)
	var todoId = getURLParam('todo_id');
	if (todoId) {
		currentTodoId = parseInt(todoId);
		console.log('Todo ID found in URL and set:', currentTodoId);
	} else {
		console.log('No todo_id found in URL parameters');
	}

	// Check for activity_id to pre-select
	var activityId = getURLParam('activity_id');
	if (activityId) {
		currentActivityId = parseInt(activityId);
		console.log('Activity ID from URL:', currentActivityId);
		// Will be applied after activities load
	}

	// Check for duration (in seconds)
	var duration = getURLParam('duration');
	if (duration) {
		var durationMinutes = Math.floor(parseInt(duration) / 60);
		$('#countdown_minutes').val(durationMinutes);
		clock.setTime(durationMinutes * 60);
		console.log('Duration from URL:', durationMinutes, 'minutes');
	}
}

// Load and resume session from session key
var loadAndResumeSession = function(sessionKey) {
	$.get('/api/get-session.php?session_key=' + sessionKey, function(response) {
		if (!response.success || !response.session) {
			console.error('Failed to load session');
			return;
		}

		var session = response.session;
		console.log('Loaded session:', session);

		// Set activity if we have the data
		if (session.activity_id) {
			currentActivityId = session.activity_id;

			// Update UI to show correct activity
			if (session.activity_name) {
				// Check if dropdown is visible (multiple activities)
				if ($('#activity_select').is(':visible')) {
					// Set dropdown to correct activity
					$('#activity_select').val(session.activity_id);
				} else {
					// Update text display
					$('#activity_text').text(session.activity_name);
				}
			}
		}

		// Check if session is still active (not yet stopped)
		if (session.actual_sec === null || session.actual_sec === undefined) {
			// Session is active - resume timer
			console.log('Resuming active session');

			// Parse start time - start_local_dt format: "YYYY-MM-DD HH:MM:SS"
			var startParts = session.start_local_dt.split(/[- :]/);
			var startTime = new Date(
				parseInt(startParts[0]), // year
				parseInt(startParts[1]) - 1, // month (0-indexed)
				parseInt(startParts[2]), // day
				parseInt(startParts[3]), // hour
				parseInt(startParts[4]), // minute
				parseInt(startParts[5])  // second
			);

			// Calculate elapsed time
			var now = new Date();
			var elapsedSec = Math.floor((now - startTime) / 1000);

			console.log('Start time:', startTime);
			console.log('Elapsed seconds:', elapsedSec);
			console.log('Intended seconds:', session.intended_sec);

			// Set session start time for stop calculation (only if owner)
			if (response.is_owner) {
				sessionStartTime = startTime;
				currentAkId = session.ak_id;
			}

			// Set the countdown minutes field
			var intendedMinutes = Math.floor(session.intended_sec / 60);
			$('#countdown_minutes').val(intendedMinutes);

			// Check if we've passed the intended time
			if (elapsedSec >= session.intended_sec) {
				// We're in bonus time
				var bonusSec = elapsedSec - session.intended_sec;
				reachedGoalTime = true;
				clock.setTime(bonusSec);
				clock.setCountdown(false);
				clock.start();
				changePageColor(successBGColor);

				// Only show stop button if owner
				if (response.is_owner) {
					$('.stop').show();
				}
			} else {
				// Still in countdown phase
				var remainingSec = session.intended_sec - elapsedSec;
				reachedGoalTime = false;
				clock.setTime(remainingSec);
				clock.setCountdown(true);
				clock.start();
				changePageColor(countingColor);

				// Only show stop button if owner
				if (response.is_owner) {
					$('.stop').show();
				}
			}

			// Hide start controls
			hideStuffs();

			// If not owner (public view), show read-only message
			if (!response.is_owner) {
				// Hide activity selector for public view
				$('#activity_text_wrapper').hide();

				var liveMessage = '<div class="live-session-notice" style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.1); border-radius: 8px;">' +
					'<h3>🔴 LIVE - Someone is doing ' + session.activity_name + '</h3>' +
					'<p style="color: #999; font-style: italic;">This is a live session (read-only)</p>' +
					'</div>';
				$('.message').html(liveMessage);
			}
		} else {
			// Session is completed - show read-only view
			console.log('Session already completed');
			console.log('Actual time:', session.actual_sec, 'seconds');
			console.log('Bonus time:', session.bonus_sec, 'seconds');

			// Show final timer state (bonus time)
			clock.setTime(session.bonus_sec || 0);
			changePageColor(successBGColor);

			// Hide all interactive controls
			$('.start').hide();
			$('.stop').hide();
			$('.duration-field-wrapper').hide();
			$('#activity_text_wrapper').hide();
			$('#post_timer_links').show(reveal_duration);


			// Show completion message
			var bonusMinutes = Math.floor(session.bonus_sec / 60);
			var completedMessage = '<div class="completion-summary">' +
				'<h3>✅ Session Completed</h3>' +
				'<p><strong>Activity:</strong> ' + session.activity_name + '</p>' +
				'<p><strong>Intended:</strong> ' + Math.floor(session.intended_sec / 60) + ' minutes</p>' +
				'<p><strong>Actual:</strong> ' + Math.floor(session.actual_sec / 60) + ' minutes</p>';

			if (session.bonus_sec > 0) {
				completedMessage += '<p><strong>Bonus:</strong> <span style="color: #4CAF50;">+' + bonusMinutes + ' minutes</span></p>';
			} else if (session.bonus_sec < 0) {
				completedMessage += '<p><strong>Status:</strong> <span style="color: #F44336;">Stopped early</span></p>';
			} else {
				completedMessage += '<p><strong>Status:</strong> <span style="color: #2196F3;">Exactly on time!</span></p>';
			}

			completedMessage += '<p class="readonly-note" style="margin-top: 20px; color: #999; font-style: italic;">This is a completed session (read-only)</p>' +
				'</div>';

			$('.message').html(completedMessage);
		}
	}).fail(function(xhr) {
		console.error('Failed to load session:', xhr.responseJSON);
		// Redirect to /mg/ if session load fails
		if (xhr.status === 404 || xhr.status === 403) {
			window.location.href = '/mg/';
		}
	});
}

// Load activities from API and populate selector
var loadActivities = function() {
	// If a session is active (session key present), don't load activity list
	// This prevents the dropdown from overwriting the read-only session activity name
	if (currentSessionKey) {
		return;
	}

	// Check if activity_id was provided in URL (before API call)
	var urlActivityId = getURLParam('activity_id');

	$.get('/api/list-activities.php', function(response) {
		if (!response.activities || response.activities.length === 0) {
			// No activities returned (shouldn't happen) - default to Meditation
			$('#activity_text').text('Meditation');
			$('#activity_text_wrapper').show();
			currentActivityId = 1;
		} else if (response.activities.length === 1) {
			// Only one activity - show as text
			$('#activity_text').text(response.activities[0].activity_name);
			$('#activity_text_wrapper').show();
			currentActivityId = response.activities[0].activity_id;
		} else {
			// Multiple activities - show dropdown
			$('#activity_select').empty(); // Clear existing options
			response.activities.forEach(function(activity) {
				$('#activity_select').append(
					$('<option>').val(activity.activity_id).text(activity.activity_name)
				);
			});
			// Only add "Add new..." option if user can create activities (Pro/Admin)
			if (response.can_create_activities) {
				$('#activity_select').append(
					$('<option>').val('add_new').text('+ Add new...')
				);
			}
			$('#activity_text_wrapper').hide();
			$('#activity_label_select').show();
			// Set default to first activity
			currentActivityId = response.activities[0].activity_id;

			// If activity_id was in URL, pre-select it
			if (urlActivityId) {
				var activityIdInt = parseInt(urlActivityId);
				// Check if this activity exists in the list
				var exists = response.activities.some(function(a) {
					return a.activity_id === activityIdInt;
				});
				if (exists) {
					currentActivityId = activityIdInt;
					$('#activity_select').val(activityIdInt);
				}
			}

			// Update currentActivityId when dropdown changes
			$('#activity_select').off('change').on('change', function() {
				var val = $(this).val();
				if (val === 'add_new') {
					showAddActivityForm();
				} else {
					currentActivityId = parseInt(val);
				}
			});
		}
	}).fail(function() {
		// If API fails, default to Meditation
		$('#activity_text').text('Meditation');
		$('#activity_text_wrapper').show();
		currentActivityId = 1;
	});
}

// Show the add activity form
var showAddActivityForm = function() {
	$('#activity_label_select').hide();
	$('#add_activity_wrapper').show();
	$('#new_activity_name').val('').focus();
}

// Hide the add activity form and restore dropdown
var hideAddActivityForm = function() {
	$('#add_activity_wrapper').hide();
	$('#activity_label_select').show();
	// Reset dropdown to current activity
	$('#activity_select').val(currentActivityId);
}

// Save new activity via API
var saveNewActivity = function() {
	var activityName = $('#new_activity_name').val().trim();
	if (!activityName) {
		alert('Please enter an activity name');
		return;
	}

	$.ajax({
		url: '/api/create-activity.php',
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({ activity_name: activityName }),
		success: function(response) {
			if (response.success) {
				// Set the new activity as current
				currentActivityId = response.activity_id;
				// Reload activities to refresh the dropdown
				loadActivities();
				// After reload, select the new activity
				setTimeout(function() {
					$('#activity_select').val(currentActivityId);
				}, 100);
				hideAddActivityForm();
			} else {
				alert('Failed to create activity: ' + (response.error || 'Unknown error'));
			}
		},
		error: function(xhr) {
			var error = xhr.responseJSON ? xhr.responseJSON.error : 'Failed to create activity';
			alert(error);
		}
	});
}

$(document).ready(function() {
	// Check URL for session key
	currentSessionKey = getSessionKeyFromURL();
	if (currentSessionKey) {
		console.log('Session key found in URL:', currentSessionKey);
	}

	clock = $('.clock').FlipClock({
		clockFace: 'MinuteCounter',
		countdown: true,
		autoStart: false,
		callbacks: {
			interval: function() {
				if(clock.getTime().time == 0 && !reachedGoalTime) {
					reachedGoalTime = true;
					clock.setCountdown(false);
					clock.start();
					countDownFinished();
				}
			}
		}
	});

	// get the number of minutes from local storage
	changePageColor(meisoPrefs.getCountingColor());
	$('#countdown_minutes').val(meisoPrefs.getMeditationTime());
	clock.setTime(meisoPrefs.getMeditationTime() * 60);

	// Apply URL parameters (may override local storage values)
	applyURLParams();

	// Load available activities for user
	loadActivities();

	// If session key in URL, load and resume that session
	if (currentSessionKey) {
		loadAndResumeSession(currentSessionKey);
	}

	$('.start').click(clickedStartButton);

	$('.stop').click(clickedStopButton);

	// Add activity handlers
	$('#save_new_activity').click(saveNewActivity);
	$('#cancel_new_activity').click(hideAddActivityForm);
	$('#new_activity_name').on('keypress', function(e) {
		if (e.which === 13) { // Enter key
			saveNewActivity();
		}
	});

    // Polling for session updates
    var pollInterval = 12000; // 12 seconds
    var pollTimer = null;

    var pollSessionStatus = function() {
        if (!currentSessionKey) return;

        // Don't poll if we are just setting up a new activity (no running session yet)
        // actually we might want to poll if we are waiting for a start on another device
        // But for now, let's focus on: if I am watching a session, update it.

        $.get('/api/get-session.php?session_key=' + currentSessionKey, function(response) {
            if (response.success && response.session) {
                var session = response.session;

                // If the session has finished (actual_sec is set), and we haven't shown it as finished yet
                if (session.actual_sec !== null && session.actual_sec !== undefined) {
                    // Check if we already know it's finished?
                    // The easiest way is to re-use loadAndResumeSession logic or similar.
                    // But we don't want to cause jitter if it's just ticking along.

                    // If UI is still counting down (clock running), stop it and show completion
                    if (clock.running) {
                        console.log('Polling detected completion!');
                        loadAndResumeSession(currentSessionKey); // refetch and render completion state
                    }
                }
            }
        });
    };

    // Start polling if we have a session key
    if (currentSessionKey) {
        pollTimer = setInterval(pollSessionStatus, pollInterval);
    }
});