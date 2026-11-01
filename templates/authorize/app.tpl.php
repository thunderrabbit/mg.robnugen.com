<?php
/**
 * @var string       $app_name
 * @var list<string> $todo_titles
 * @var string       $client_id
 * @var string       $redirect_uri
 * @var string       $code_challenge
 * @var string       $code_challenge_method
 * @var string       $state
 */
?>
<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Connect <?= htmlspecialchars($app_name) ?></h1>
    </header>

    <div class="card">
        <p>
            Let <strong><?= htmlspecialchars($app_name) ?></strong> log meditation sessions and tick
            <strong><?= htmlspecialchars(implode(', ', $todo_titles)) ?></strong> for you?
        </p>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="response_type"         value="code">
            <input type="hidden" name="client_id"             value="<?= htmlspecialchars($client_id) ?>">
            <input type="hidden" name="redirect_uri"          value="<?= htmlspecialchars($redirect_uri) ?>">
            <input type="hidden" name="code_challenge"        value="<?= htmlspecialchars($code_challenge) ?>">
            <input type="hidden" name="code_challenge_method" value="<?= htmlspecialchars($code_challenge_method) ?>">
            <input type="hidden" name="state"                 value="<?= htmlspecialchars($state) ?>">

            <div class="form-actions">
                <button type="submit" name="decision" value="allow" class="btn-primary">Allow</button>
                <button type="submit" name="decision" value="deny" class="btn-cancel">Deny</button>
            </div>
        </form>
    </div>
</div>
