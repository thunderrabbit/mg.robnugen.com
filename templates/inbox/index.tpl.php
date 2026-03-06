<div class="dashboard-container">
    <header class="dashboard-header">
        <h1>Agent Inbox</h1>
        <p class="subtitle">Send instructions to Claude. They'll be read at the start of each session.</p>
    </header>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <div class="card" style="max-width: 800px; margin: 0 auto 2rem;">
        <form method="POST" action="/inbox/">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="inbox_action" value="send">

            <div class="form-group">
                <label for="message">Message</label>
                <textarea id="message" name="message" class="form-control" cols="80" rows="15" placeholder="e.g., Remember to check the deploy logs today..." required></textarea>
            </div>

            <div class="form-group">
                <label for="priority">Priority</label>
                <select id="priority" name="priority" class="form-control">
                    <option value="high">High</option>
                    <option value="normal" selected>Normal</option>
                    <option value="low">Low</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Send to Agent</button>
            </div>
        </form>
    </div>

    <?php if (!empty($messages)): ?>
    <div class="card" style="max-width: 800px; margin: 0 auto;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2>Messages</h2>
            <?php if ($show_archived): ?>
                <a href="/inbox/">Hide archived</a>
            <?php else: ?>
                <a href="/inbox/?show_archived">Show archived</a>
            <?php endif; ?>
        </div>
        <div class="inbox-list">
            <?php foreach ($messages as $msg): ?>
                <div class="inbox-item <?= $msg['archived_at'] ? 'inbox-archived' : ($msg['done_at'] ? 'inbox-done' : ($msg['seen_at'] ? 'inbox-seen' : 'inbox-pending')) ?>">
                    <div class="inbox-meta">
                        <span class="inbox-priority inbox-priority-<?= $msg['priority'] ?>"><?= $msg['priority'] ?></span>
                        <span class="inbox-status">
                            <?php if ($msg['archived_at']): ?>
                                Archived <?= date('M j g:ia', strtotime($msg['archived_at'])) ?>
                            <?php elseif ($msg['done_at']): ?>
                                Done <?= date('M j g:ia', strtotime($msg['done_at'])) ?>
                            <?php elseif ($msg['seen_at']): ?>
                                Seen <?= date('M j g:ia', strtotime($msg['seen_at'])) ?>
                            <?php else: ?>
                                Pending
                            <?php endif; ?>
                        </span>
                        <span class="inbox-date"><?= date('M j g:ia', strtotime($msg['created_at'])) ?></span>
                    </div>
                    <div class="inbox-message"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                    <?php if ($msg['response']): ?>
                        <div class="inbox-response">
                            <strong>Agent response:</strong> <?= nl2br(htmlspecialchars($msg['response'])) ?>
                        </div>
                    <?php endif; ?>
                    <div class="inbox-actions">
                        <button type="button" class="btn-sm btn-edit" onclick="toggleEdit(<?= $msg['message_id'] ?>)">Edit</button>
                        <?php if (!$msg['archived_at']): ?>
                        <form method="POST" action="/inbox/" class="inbox-action-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="inbox_action" value="archive">
                            <input type="hidden" name="message_id" value="<?= $msg['message_id'] ?>">
                            <button type="submit" class="btn-sm btn-archive">Archive</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" action="/inbox/" class="inbox-action-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="inbox_action" value="delete">
                            <input type="hidden" name="message_id" value="<?= $msg['message_id'] ?>">
                            <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Permanently delete this message?')">Delete</button>
                        </form>
                    </div>
                    <form method="POST" action="/inbox/" class="inbox-edit-form" id="edit-<?= $msg['message_id'] ?>" style="display:none;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="inbox_action" value="edit">
                        <input type="hidden" name="message_id" value="<?= $msg['message_id'] ?>">
                        <textarea name="message" class="form-control" rows="4"><?= htmlspecialchars($msg['message']) ?></textarea>
                        <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; align-items: center;">
                            <select name="priority" class="form-control" style="width: auto;">
                                <option value="high" <?= $msg['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                                <option value="normal" <?= $msg['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                                <option value="low" <?= $msg['priority'] === 'low' ? 'selected' : '' ?>>Low</option>
                            </select>
                            <button type="submit" class="btn-sm btn-save">Save</button>
                            <button type="button" class="btn-sm btn-archive" onclick="toggleEdit(<?= $msg['message_id'] ?>)">Cancel</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="card" style="max-width: 800px; margin: 0 auto; text-align: center; padding: 2rem;">
        <p>No messages yet. Send one above!</p>
    </div>
    <?php endif; ?>
</div>

<style>
    .subtitle { color: var(--text-muted); margin-top: 0.25rem; }
    .card { background: var(--bg-card); padding: 1.5rem; border-radius: var(--radius-lg); box-shadow: var(--shadow-md); }
    .form-group { margin-bottom: 1rem; }
    .form-actions { display: flex; justify-content: flex-end; }
    .alert { padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1rem; max-width: 800px; margin-left: auto; margin-right: auto; }
    .alert-error { background: #fee; border: 1px solid #c00; color: #900; }
    .alert-success { background: #efe; border: 1px solid #0a0; color: #060; }

    .inbox-list { display: flex; flex-direction: column; gap: 1rem; }
    .inbox-item { border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem; position: relative; }
    .inbox-pending { border-left: 3px solid var(--primary); }
    .inbox-seen { border-left: 3px solid var(--warning, #f90); opacity: 0.85; }
    .inbox-done { border-left: 3px solid var(--success, #0a0); opacity: 0.6; }
    .inbox-archived { border-left: 3px solid var(--neutral, #999); opacity: 0.5; }

    .inbox-meta { display: flex; gap: 0.75rem; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem; align-items: center; }
    .inbox-priority { text-transform: uppercase; font-weight: 700; font-size: 0.7rem; padding: 0.1rem 0.4rem; border-radius: 3px; }
    .inbox-priority-high { background: #fdd; color: #c00; }
    .inbox-priority-normal { background: #eee; color: #555; }
    .inbox-priority-low { background: #eef; color: #66a; }

    .inbox-message { white-space: pre-wrap; line-height: 1.5; }
    .inbox-response { margin-top: 0.75rem; padding: 0.75rem; background: var(--bg-secondary, #f5f5f5); border-radius: var(--radius-sm, 4px); font-size: 0.9rem; }

    .inbox-actions { position: absolute; top: 0.75rem; right: 0.75rem; display: flex; gap: 0.4rem; }
    .inbox-action-form { display: inline; }
    .btn-sm { font-size: 0.75rem; padding: 0.2rem 0.5rem; cursor: pointer; }
    .btn-archive { background: transparent; border: 1px solid var(--neutral, #999); color: var(--text-muted); border-radius: 3px; }
    .btn-archive:hover { background: var(--neutral, #999); color: #fff; }
    .btn-danger { background: transparent; border: 1px solid #c00; color: #c00; border-radius: 3px; }
    .btn-danger:hover { background: #c00; color: #fff; }
    .btn-edit { background: transparent; border: 1px solid var(--info, #2196F3); color: var(--info, #2196F3); border-radius: 3px; }
    .btn-edit:hover { background: var(--info, #2196F3); color: #fff; }
    .btn-save { background: var(--success, #4CAF50); border: 1px solid var(--success, #4CAF50); color: #fff; border-radius: 3px; }
    .btn-save:hover { background: var(--success-hover, #45a049); }
    .inbox-edit-form { margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color); }
</style>
<script>
function toggleEdit(id) {
    var form = document.getElementById('edit-' + id);
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}
</script>
