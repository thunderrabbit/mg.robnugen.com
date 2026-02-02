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
	<!-- Add new activity input (shown when "Add new..." selected) -->
	<div id="add_activity_wrapper" style="display:none;">
		<input type="text" id="new_activity_name" placeholder="Activity name" maxlength="64" />
		<button id="save_new_activity" type="button">Save</button>
		<button id="cancel_new_activity" type="button">Cancel</button>
	</div>
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
<div id="post_timer_links" class="hidden">
	<a href="/mg/" class="post-timer-link">Start New Timer</a>
	<?php if ($is_logged_in->isLoggedIn() && ($is_logged_in->isAdmin() || $is_logged_in->isPaid())): ?>
	<a href="/" class="post-timer-link">Dashboard</a>
	<?php endif; ?>
	<?php if ($is_logged_in->isLoggedIn() && $is_logged_in->isAdmin()): ?>
	<a href="/admin/" class="post-timer-link">Admin</a>
	<?php endif; ?>
</div>
<audio id="audio-bell" src="/mg/assets/124742__tec-studios__mono-bell-11-d-18sec.wav" preload="auto"></audio>
