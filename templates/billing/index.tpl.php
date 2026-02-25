<div class="PagePanel">
    <div class="head"><h5>Billing</h5></div>

    <p>Credits remaining: <strong><?= (int)$credits_remaining ?></strong></p>

    <?php if ($stripe_error): ?>
        <div class="error-message" style="color: red; padding: 10px; margin: 10px 0; border: 1px solid red; background-color: #ffe8e8;">
            Something went wrong with Stripe. Please try again or contact support.
        </div>
    <?php endif; ?>

    <?php if (!$stripe_configured): ?>
        <p style="color: #666;">Paid plans are not yet available. Check back soon.</p>
    <?php else: ?>

    <p>Each plan adds credits monthly. Credits are consumed when agents use the API.</p>

    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin: 20px 0;">

        <div class="PagePanel" style="flex: 1; min-width: 220px; border: 2px solid #ccc; padding: 20px;">
            <h3 style="margin-top: 0;">Developer</h3>
            <p style="font-size: 1.5em; font-weight: bold;">$5 / month</p>
            <ul style="padding-left: 20px;">
                <li>5,000 credits / month</li>
                <li>Full API access</li>
                <li>Cancel any time</li>
            </ul>
            <form action="/billing/checkout.php" method="POST">
                <input type="hidden" name="plan" value="developer">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="submit" value="Subscribe — Developer" class="greyishBtn submitForm" style="width: 100%;">
            </form>
        </div>

        <div class="PagePanel" style="flex: 1; min-width: 220px; border: 2px solid #5a9; padding: 20px;">
            <h3 style="margin-top: 0;">Growth</h3>
            <p style="font-size: 1.5em; font-weight: bold;">$15 / month</p>
            <ul style="padding-left: 20px;">
                <li>25,000 credits / month</li>
                <li>Full API access</li>
                <li>Cancel any time</li>
            </ul>
            <form action="/billing/checkout.php" method="POST">
                <input type="hidden" name="plan" value="growth">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="submit" value="Subscribe — Growth" class="greyishBtn submitForm" style="width: 100%; background: #5a9; color: #fff;">
            </form>
        </div>

    </div>

    <p style="color: #888; font-size: 0.9em;">
        Payments are processed securely by <a href="https://stripe.com" target="_blank" rel="noopener">Stripe</a>.
    </p>

    <?php endif; ?>
</div>
