<div class="PagePanel">
    <div class="head"><h5>Payment Successful</h5></div>

    <p>Thank you! Your subscription is active.</p>

    <p>
        Credits are added by our payment processor within a few seconds.
        If your balance hasn't updated yet, refresh this page or check back shortly.
    </p>

    <p>Credits remaining: <strong><?= (int)$credits_remaining ?></strong></p>

    <p>
        <a href="/billing/" class="greyishBtn" style="display:inline-block; padding: 8px 16px; text-decoration:none;">Back to Billing</a>
        &nbsp;
        <a href="/profile/" class="greyishBtn" style="display:inline-block; padding: 8px 16px; text-decoration:none;">Profile &amp; API Keys</a>
    </p>
</div>
