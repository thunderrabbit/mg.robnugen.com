var MeisoPreferences = function() {

	// the values in prefs are only defaults that will be overwritten if user clicks save prefs
	var meditationTime = 5;
	var revealDuration = 2000;
	var hideDuration = 2000;
	var successBGColor = "#0B6138";
	var countingColor = "#232323";

	function saveThatInThis(variable, value) {
		localStorage[variable] = JSON.stringify(value);
	}

	function getThat(variable) {
		return localStorage[variable] ? JSON.parse(localStorage[variable]) : undefined;
	}

	function makeASaver(variable) {
		return function(value) {
			saveThatInThis(variable,value);
		}
	}

	function makeReader(variable, default_value) {
		return function() {
			return getThat(variable) || default_value;
		}
	}

	return {
		setMeditationTime	: makeASaver('meditation_time'),
		getMeditationTime	: makeReader('meditation_time', meditationTime),
		setRevealDuration	: makeASaver('reveal_duration'),
		getRevealDuration	: makeReader('reveal_duration', revealDuration),
		setHideDuration  	: makeASaver('hide_duration'),
		getHideDuration  	: makeReader('hide_duration', hideDuration),
		setSuccessBGColor  	: makeASaver('success_color'),
		getSuccessBGColor  	: makeReader('success_color', successBGColor),
		setCountingColor  	: makeASaver('counting_color'),
		getCountingColor  	: makeReader('counting_color', countingColor),
	}

};