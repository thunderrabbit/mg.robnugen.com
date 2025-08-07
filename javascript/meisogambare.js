var clock;
var reachedGoalTime = false;

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
}

var clickedStopButton = function(e) {
	clock.stop();
	setSuccessString();
	revealStuffs();
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

	$('.start').click(clickedStartButton);

	$('.stop').click(clickedStopButton)
});