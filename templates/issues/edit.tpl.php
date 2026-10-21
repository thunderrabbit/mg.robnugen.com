<?php

/**
 * Edit one issue.
 *
 * @var \Issues\IssueDetail $issue
 * @var array<int, array<string, mixed>> $assignee_choices
 * @var string $error
 * @var mixed $form_title
 * @var mixed $form_description
 * @var mixed $form_priority
 * @var mixed $form_assignee_aiu
 */

?>
<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/issues/view.php?issue_id=<?= $issue->issueId ?>" class="btn-sm btn-back">← Back to issue</a>
        <h1>Edit <span class="issue-id">#<?= $issue->issueId ?></span></h1>
    </header>

    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/issues/edit.php">
            <?= csrf_field() ?>
            <input type="hidden" name="issue_id" value="<?= $issue->issueId ?>">

            <div class="form-field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="255" required
                       value="<?= htmlspecialchars($form_title ?? $issue->title) ?>">
            </div>

            <div class="form-field">
                <label for="description">Description (optional)</label>
                <textarea id="description" name="description" rows="6"><?= htmlspecialchars($form_description ?? $issue->description ?? '') ?></textarea>
            </div>

            <div class="form-field">
                <label for="assignee_aiu">Assignee</label>
                <?php
                    $selected_assignee = isset($form_assignee_aiu)
                        ? ($form_assignee_aiu === '' ? null : (int)$form_assignee_aiu)
                        : $issue->assigneeAiu;
                ?>
                <select id="assignee_aiu" name="assignee_aiu">
                    <option value=""<?= $selected_assignee === null ? ' selected' : '' ?>>— unassigned —</option>
                    <?php foreach ($assignee_choices as $choice): ?>
                        <option value="<?= (int)$choice['aiu_id'] ?>"<?= $selected_assignee === (int)$choice['aiu_id'] ? ' selected' : '' ?>>
                            <?= htmlspecialchars($choice['name']) ?> (<?= htmlspecialchars($choice['actor_type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field">
                <label for="priority">Priority</label>
                <?php $sel = $form_priority ?? $issue->priority->value; ?>
                <select id="priority" name="priority">
                    <option value="low"<?= $sel === 'low' ? ' selected' : '' ?>>Low</option>
                    <option value="normal"<?= $sel === 'normal' ? ' selected' : '' ?>>Normal</option>
                    <option value="high"<?= $sel === 'high' ? ' selected' : '' ?>>High</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save changes</button>
                <a href="/issues/view.php?issue_id=<?= $issue->issueId ?>" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>
