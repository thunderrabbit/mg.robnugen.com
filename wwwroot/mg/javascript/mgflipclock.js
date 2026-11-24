// A minutes:seconds flip clock for the meditation timer. It answers the
// calls meisogambare.js made to FlipClock 0.7.8: setTime, getTime,
// setCountdown, start, stop and running, plus an interval callback that runs
// once for every second that passes. It needs no library.
//
//   var clock = MgFlipClock(element, {countdown: true, callbacks: {interval: fn}});
//
// Time is read from the wall clock, not counted in ticks, so a tab the
// browser throttles in the background still shows the right time.
function MgFlipClock(element, options) {
	options = options || {};
	var callbacks = options.callbacks || {};
	var clock = {
		running: false,
		countdown: options.countdown !== false
	};
	var seconds = 0;          // whole seconds shown
	var startedAt = 0;        // wall-clock ms when start() ran
	var startedFrom = 0;      // seconds shown when start() ran
	var timer = null;
	var digits = [];          // one {card, front, back} per digit, left to right

	function makeDigit() {
		var card = document.createElement('span');
		card.className = 'mg-flip-digit';
		var front = document.createElement('span');
		front.className = 'mg-flip-face';
		front.textContent = '0';
		card.appendChild(front);
		return {card: card, front: front, text: '0'};
	}

	function makeSeparator() {
		var colon = document.createElement('span');
		colon.className = 'mg-flip-colon';
		colon.textContent = ':';
		return colon;
	}

	var minutesBox = document.createElement('span');
	minutesBox.className = 'mg-flip-group';
	var secondsBox = document.createElement('span');
	secondsBox.className = 'mg-flip-group';
	var minuteDigits = [];
	var secondDigits = [makeDigit(), makeDigit()];
	secondDigits.forEach(function(d) { secondsBox.appendChild(d.card); });

	element.classList.add('mg-flip-clock');
	element.textContent = '';
	element.appendChild(minutesBox);
	element.appendChild(makeSeparator());
	element.appendChild(secondsBox);

	function setDigit(digit, text) {
		if (digit.text === text) {
			return;
		}
		digit.text = text;
		digit.front.textContent = text;
		digit.card.classList.remove('mg-flip-turn');
		void digit.card.offsetWidth;   // restart the animation
		digit.card.classList.add('mg-flip-turn');
	}

	function render() {
		var minutes = String(Math.floor(seconds / 60));
		if (minutes.length < 2) {
			minutes = '0' + minutes;
		}
		while (minuteDigits.length < minutes.length) {
			var extra = makeDigit();
			minuteDigits.push(extra);
			minutesBox.appendChild(extra.card);
		}
		// 100:00 counting down to 99:59 gives a digit back.
		while (minuteDigits.length > minutes.length) {
			minutesBox.removeChild(minuteDigits.pop().card);
		}
		for (var i = 0; i < minuteDigits.length; i++) {
			setDigit(minuteDigits[i], minutes.charAt(i));
		}
		var rest = seconds % 60;
		setDigit(secondDigits[0], String(Math.floor(rest / 10)));
		setDigit(secondDigits[1], String(rest % 10));
	}

	function secondsNow() {
		var passed = Math.floor((Date.now() - startedAt) / 1000);
		var value = clock.countdown ? startedFrom - passed : startedFrom + passed;
		return value < 0 ? 0 : value;
	}

	function tick() {
		var next = secondsNow();
		if (next === seconds) {
			return;
		}
		seconds = next;
		render();
		if (typeof callbacks.interval === 'function') {
			callbacks.interval.call(clock);
		}
		// A countdown stops at zero unless the callback turned it round.
		if (clock.running && clock.countdown && seconds === 0) {
			clock.stop();
		}
	}

	clock.setTime = function(value) {
		seconds = Math.max(0, Math.floor(Number(value) || 0));
		startedAt = Date.now();
		startedFrom = seconds;
		render();
	};

	clock.getTime = function() {
		return {time: seconds};
	};

	clock.setCountdown = function(on) {
		// Counting from the time now shown in the new direction.
		clock.countdown = !!on;
		startedAt = Date.now();
		startedFrom = seconds;
	};

	clock.start = function() {
		if (clock.running) {
			return;
		}
		startedAt = Date.now();
		startedFrom = seconds;
		clock.running = true;
		timer = setInterval(tick, 200);
	};

	clock.stop = function() {
		if (timer !== null) {
			clearInterval(timer);
			timer = null;
		}
		clock.running = false;
	};

	render();
	return clock;
}

if (typeof module !== 'undefined' && module.exports) {
	module.exports = MgFlipClock;
}
