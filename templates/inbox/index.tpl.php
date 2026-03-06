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
                <textarea id="message" name="message" class="form-control" rows="3" placeholder="e.g., Remember to check the deploy logs today..." required></textarea>
            </div>

            <div class="form-group">
                <label for="priority">Priority</label>
                <select id="priority" name="priority" class="form-control">
                    <option value="normal" selected>Normal</option>
                    <option value="high">High</option>
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
        <h2>Messages</h2>
        <div class="inbox-list">
            <?php foreach ($messages as $msg): ?>
                <div class="inbox-item <?= $msg['done_at'] ? 'inbox-done' : ($msg['seen_at'] ? 'inbox-seen' : 'inbox-pending') ?>">
                    <div class="inbox-meta">
                        <span class="inbox-priority inbox-priority-<?= $msg['priority'] ?>"><?= $msg['priority'] ?></span>
                        <span class="inbox-status">
                            <?php if ($msg['done_at']): ?>
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
                    <form method="POST" action="/inbox/" class="inbox-delete-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="inbox_action" value="delete">
                        <input type="hidden" name="message_id" value="<?= $msg['message_id'] ?>">
                        <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Delete this message?')">Delete</button>
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

    .inbox-meta { display: flex; gap: 0.75rem; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem; align-items: center; }
    .inbox-priority { text-transform: uppercase; font-weight: 700; font-size: 0.7rem; padding: 0.1rem 0.4rem; border-radius: 3px; }
    .inbox-priority-high { background: #fdd; color: #c00; }
    .inbox-priority-normal { background: #eee; color: #555; }
    .inbox-priority-low { background: #eef; color: #66a; }

    .inbox-message { white-space: pre-wrap; line-height: 1.5; }
    .inbox-response { margin-top: 0.75rem; padding: 0.75rem; background: var(--bg-secondary, #f5f5f5); border-radius: var(--radius-sm, 4px); font-size: 0.9rem; }

    .inbox-delete-form { position: absolute; top: 0.75rem; right: 0.75rem; }
    .btn-sm { font-size: 0.75rem; padding: 0.2rem 0.5rem; cursor: pointer; }
    .btn-danger { background: transparent; border: 1px solid #c00; color: #c00; border-radius: 3px; }
    .btn-danger:hover { background: #c00; color: #fff; }
</style>
