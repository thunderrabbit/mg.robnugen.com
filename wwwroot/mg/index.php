<?php
# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Check URL for session key pattern: /mg/{session_key}
$uri = $_SERVER['REQUEST_URI'] ?? '';
if (preg_match('#^/mg/([a-zA-Z0-9_-]{11})(?:\?.*)?$#', $uri, $matches)) {
    $session_key = $matches[1];

    // Initialize database connection and helper
    $pdo = \Database\Base::getPDO($config);
    $sessionKeyHelper = new \ActivityTracking\SessionKey($pdo);

    // Verify ownership if logged in
    if ($is_logged_in->isLoggedIn()) {
        $user_id = $is_logged_in->loggedInID();

        // Check if user owns this session
        if (!$sessionKeyHelper->isOwner($session_key, $user_id)) {
            // Not owner - check if it's public
            $publicSession = $sessionKeyHelper->getPublicSessionByKey($session_key);
            if (!$publicSession) {
                // Not public or doesn't exist - redirect to /mg/
                header("Location: /mg/");
                exit;
            }
            // Session is public - continue to show public view
        }
        // User owns this session - continue to show timer page
    } else {
        // Not logged in - check if session is public
        $publicSession = $sessionKeyHelper->getPublicSessionByKey($session_key);
        if (!$publicSession) {
            // Not public or doesn't exist - redirect to /mg/
            header("Location: /mg/");
            exit;
        }
        // Session is public - continue to show public view
    }
}
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
	<script src="javascript/<?= SEMVER ?>/meisoprefs.js"></script>
	<script src="javascript/<?= SEMVER ?>/meisogambare.js"></script>
</body>
</html>
