<?php
# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';
?>
<html>
<head>

	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flipclock@0.7.8/compiled/flipclock.min.css">
	<link rel="stylesheet" href="css/meisogambare.css">
</head>
<body class="body">
	<div class="duration-field-wrapper">
		<label for="countdown_minutes">Countdown minutes:</label>
		<input type="text" id="countdown_minutes" value="" />
	</div>
	<div class="activity-selector-wrapper">
		<!-- Shown when 2+ activities available -->
		<label for="activity_select" id="activity_label_select" style="display:none;">Activity:
			<select id="activity_select">
			</select>
		</label>
		<!-- Shown when 0-1 activities available -->
		<label id="activity_text_wrapper">Activity: <span id="activity_text"></span></label>
	</div>
	<div class="clock-wrapper">
		<div class="clock"></div>
		<div class="message"></div>
		<button class="start">Start Clock</button>
		<button class="stop hidden">Stop Clock</button>
	</div>
	<div class="share hidden">
		<input id="share_success_string" type="hidden" />
		<a id="twitter_link" href="http://twitter.com/">twitter</a>
	</div>
	<audio id="audio-bell" src="assets/124742__tec-studios__mono-bell-11-d-18sec.wav" preload="auto"></audio>
	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/flipclock@0.7.8/compiled/flipclock.min.js"></script>
	<script src="javascript/meisoprefs.js"></script>
	<script src="javascript/meisogambare.js"></script>
</body>
</html>
