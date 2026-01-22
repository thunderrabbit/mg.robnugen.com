var clock;
var reachedGoalTime = false;
var sessionStartTime = null; // Track when session started
var currentAkId = null; // Track current activity session ID
var currentActivityId = 1; // Default to Meditation, will be updated when activities load

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

	$.ajax({
		url: '/api/stop-activity.php',
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({
			ak_id: currentAkId,
			actual_sec: actualSec,
			bonus_sec: bonusSec
		}),
		success: function(response) {
			if (response.success) {
				console.log('Activity session stopped successfully');
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

var hideStuffs = function() {
	$('.start').hide(hide_duration);
	$('.duration-field-wrapper').hide(hide_duration);
	$('.share').hide(reveal_duration);
}

var revealStopButton = function() {
	$('.stop').show(reveal_duration);
}

var countDownFinished = function() {
	document.getElementById("audio-bell").play();
	revealStopButton();
	changePageColor(successBGColor);
}

var revealStuffs = function() {
	$('.stop').hide(reveal_duration);
	$('.share').show(reveal_duration);
	$('.start').show(hide_duration);
	$('.duration-field-wrapper').show(hide_duration);

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

// Load activities from API and populate selector
var loadActivities = function() {
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
			response.activities.forEach(function(activity) {
				$('#activity_select').append(
					$('<option>').val(activity.activity_id).text(activity.activity_name)
				);
			});
			$('#activity_text_wrapper').hide();
			$('#activity_label_select').show();
			// Set default to first activity
			currentActivityId = response.activities[0].activity_id;

			// Update currentActivityId when dropdown changes
			$('#activity_select').on('change', function() {
				currentActivityId = parseInt($(this).val());
			});
		}
	}).fail(function() {
		// If API fails, default to Meditation
		$('#activity_text').text('Meditation');
		$('#activity_text_wrapper').show();
		currentActivityId = 1;
	});
}

$(document).ready(function() {
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

	// Load available activities for user
	loadActivities();

	$('.start').click(clickedStartButton);

	$('.stop').click(clickedStopButton)
});