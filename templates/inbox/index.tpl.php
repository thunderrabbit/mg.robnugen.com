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

            <div style="display: flex; gap: 1rem; align-items: flex-end;">
                <div class="form-group" style="flex: 0 0 auto;">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority" class="form-control">
                        <option value="high">High</option>
                        <option value="normal" selected>Normal</option>
                        <option value="low">Low</option>
                    </select>
                </div>
                <div class="form-group" style="flex: 0 0 auto;">
                    <label for="show_date">Show after</label>
                    <input type="date" id="show_date" name="show_date" class="form-control" placeholder="Visible immediately if blank">
                </div>
                <div class="form-actions" style="flex: 1; margin-bottom: 1rem;">
                    <button type="submit" class="btn-primary">Send to Agent</button>
                </div>
            </div>
        </form>
    </div>

    <?php
    // Helper to build query string, toggling/setting a single param
    function inbox_url($overrides = [], $removes = []) {
        global $show_archived, $show_future, $filter_priority, $filter_status, $sort_by, $sort_dir;
        $params = [];
        if ($show_archived) $params['show_archived'] = '';
        if ($show_future) $params['show_future'] = '';
        if ($filter_priority) $params['priority'] = $filter_priority;
        if ($filter_status) $params['status'] = $filter_status;
        if ($sort_by && $sort_by !== 'default') $params['sort'] = $sort_by;
        if ($sort_dir === 'asc') $params['dir'] = 'asc';

        foreach ($overrides as $k => $v) {
            if ($v === '' && in_array($k, ['show_archived', 'show_future'])) {
                $params[$k] = '';
            } elseif ($v === null || $v === '') {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        foreach ($removes as $r) unset($params[$r]);

        if (empty($params)) return '/inbox/';
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $v === '' ? htmlspecialchars($k) : htmlspecialchars($k) . '=' . htmlspecialchars($v);
        }
        return '/inbox/?' . implode('&', $parts);
    }

    function sort_link($label, $field) {
        global $sort_by, $sort_dir;
        $active = ($sort_by === $field);
        $new_dir = ($active && $sort_dir === 'desc') ? 'asc' : 'desc';
        $arrow = $active ? ($sort_dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
        $cls = $active ? 'inbox-filter-active' : '';
        return '<a href="' . inbox_url(['sort' => $field, 'dir' => $new_dir]) . '" class="' . $cls . '">' . $label . $arrow . '</a>';
    }
    ?>

    <?php if (!empty($messages) || $filter_priority || $filter_status): ?>
    <div class="card" style="max-width: 800px; margin: 0 auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <h2>Messages</h2>
            <div style="display: flex; gap: 1rem; font-size: 0.85rem;">
                <?php if ($show_archived): ?>
                    <a href="<?= inbox_url([], ['show_archived']) ?>">Hide archived</a>
                <?php else: ?>
                    <a href="<?= inbox_url(['show_archived' => '']) ?>">Show archived</a>
                <?php endif; ?>
                <?php if ($show_future): ?>
                    <a href="<?= inbox_url([], ['show_future']) ?>">Hide future</a>
                <?php else: ?>
                    <a href="<?= inbox_url(['show_future' => '']) ?>">Show future</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="inbox-controls">
            <div class="inbox-control-group">
                <span class="inbox-control-label">Sort:</span>
                <?= sort_link('ID', 'id') ?>
                <?= sort_link('Priority', 'priority') ?>
                <?= sort_link('Date', 'date') ?>
                <?= sort_link('Status', 'status') ?>
                <?php if ($sort_by !== 'default'): ?>
                    <a href="<?= inbox_url([], ['sort', 'dir']) ?>" class="inbox-filter-clear">reset</a>
                <?php endif; ?>
            </div>
            <div class="inbox-control-group">
                <span class="inbox-control-label">Priority:</span>
                <?php foreach (['high', 'normal', 'low'] as $p): ?>
                    <?php if ($filter_priority === $p): ?>
                        <a href="<?= inbox_url([], ['priority']) ?>" class="inbox-filter-active"><?= $p ?> &times;</a>
                    <?php else: ?>
                        <a href="<?= inbox_url(['priority' => $p]) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="inbox-control-group">
                <span class="inbox-control-label">Status:</span>
                <?php foreach (['pending', 'seen', 'done', 'archived'] as $s): ?>
                    <?php if ($filter_status === $s): ?>
                        <a href="<?= inbox_url([], ['status']) ?>" class="inbox-filter-active"><?= $s ?> &times;</a>
                    <?php else: ?>
                        <a href="<?= inbox_url(['status' => $s]) ?>"><?= $s ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (!empty($messages)): ?>
        <div class="inbox-list">
            <?php foreach ($messages as $msg): ?>
                <div class="inbox-item <?= $msg['archived_at'] ? 'inbox-archived' : ($msg['done_at'] ? 'inbox-done' : ($msg['seen_at'] ? 'inbox-seen' : 'inbox-pending')) ?>" id="inbox-msg-<?= $msg['message_id'] ?>">
                    <div class="inbox-meta">
                        <span class="inbox-id">#<?= $msg['message_id'] ?></span>
                        <span class="inbox-priority inbox-priority-<?= $msg['priority'] ?>"><?= $msg['priority'] ?></span>
                        <span class="inbox-status">
                            <?php if ($msg['archived_at']): ?>
                                Archived <span class="utc-time" data-utc="<?= $msg['archived_at'] ?>"></span>
                            <?php elseif ($msg['done_at']): ?>
                                Done <span class="utc-time" data-utc="<?= $msg['done_at'] ?>"></span>
                            <?php elseif ($msg['seen_at']): ?>
                                Seen <span class="utc-time" data-utc="<?= $msg['seen_at'] ?>"></span>
                            <?php else: ?>
                                Pending
                            <?php endif; ?>
                            <?php if ($msg['show_date']): ?>
                                · <span class="inbox-show-date" title="Deferred until this date">Show: <span class="utc-time" data-utc="<?= $msg['show_date'] ?>"></span></span>
                            <?php endif; ?>
                            · <span class="inbox-date"><span class="utc-time" data-utc="<?= $msg['created_at'] ?>"></span></span>
                        </span>
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
                        <button type="button" class="btn-sm btn-archive" onclick="archiveMessage(<?= $msg['message_id'] ?>)">Archive</button>
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
                        <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                            <select name="priority" class="form-control" style="width: auto;">
                                <option value="high" <?= $msg['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                                <option value="normal" <?= $msg['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                                <option value="low" <?= $msg['priority'] === 'low' ? 'selected' : '' ?>>Low</option>
                            </select>
                            <label style="font-size: 0.8rem; color: var(--text-muted);">Show after:
                                <input type="date" name="show_date" class="form-control" style="width: auto; display: inline-block;" value="<?= $msg['show_date'] ? htmlspecialchars(substr($msg['show_date'], 0, 10)) : '' ?>">
                            </label>
                            <button type="submit" class="btn-sm btn-save">Save</button>
                            <button type="button" class="btn-sm btn-archive" onclick="toggleEdit(<?= $msg['message_id'] ?>)">Cancel</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
            <?php if ($filter_priority || $filter_status): ?>
                <p>No messages match the current filters. <a href="/inbox/">Clear filters</a></p>
            <?php else: ?>
                <p>No messages to display.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
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

    .inbox-controls { display: flex; flex-wrap: wrap; gap: 0.5rem 1.5rem; padding: 0.75rem 0; margin-bottom: 0.75rem; border-bottom: 1px solid var(--border-color); font-size: 0.8rem; }
    .inbox-control-group { display: flex; align-items: center; gap: 0.4rem; }
    .inbox-control-label { color: var(--text-muted); font-weight: 600; }
    .inbox-controls a { color: var(--text-muted); text-decoration: none; padding: 0.15rem 0.4rem; border-radius: 3px; }
    .inbox-controls a:hover { background: var(--bg-secondary, #f0f0f0); color: var(--text-primary, #333); }
    .inbox-filter-active { background: var(--primary, #2196F3) !important; color: #fff !important; }
    .inbox-filter-clear { font-size: 0.7rem; color: var(--text-muted); text-decoration: underline; }

    .inbox-list { display: flex; flex-direction: column; gap: 1rem; }
    .inbox-item { border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem; position: relative; transition: opacity 0.3s, max-height 0.3s; overflow: hidden; }
    .inbox-pending { border-left: 3px solid var(--primary); }
    .inbox-seen { border-left: 3px solid var(--warning, #f90); opacity: 0.85; }
    .inbox-done { border-left: 3px solid var(--success, #0a0); opacity: 0.6; }
    .inbox-archived { border-left: 3px solid var(--neutral, #999); opacity: 0.5; }
    .inbox-removing { opacity: 0; max-height: 0; padding: 0; margin: 0; border: none; }

    .inbox-meta { display: flex; flex-wrap: wrap; gap: 0.75rem; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem; align-items: center; padding-right: 10rem; }
    .inbox-id { font-family: monospace; font-size: 0.75rem; color: var(--text-muted); }
    .inbox-priority { text-transform: uppercase; font-weight: 700; font-size: 0.7rem; padding: 0.1rem 0.4rem; border-radius: 3px; }
    .inbox-priority-high { background: #fdd; color: #c00; }
    .inbox-priority-normal { background: #eee; color: #555; }
    .inbox-priority-low { background: #eef; color: #66a; }
    .inbox-show-date { font-style: italic; color: var(--info, #2196F3); }

    .inbox-message { white-space: pre-wrap; line-height: 1.5; }
    .inbox-response { margin-top: 0.75rem; padding: 0.75rem; background: var(--bg-secondary, #f5f5f5); border-radius: var(--radius-sm, 4px); font-size: 0.9rem; }

    .inbox-actions { position: absolute; top: 0.75rem; right: 0.75rem; display: flex; gap: 0.4rem; z-index: 10; }
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

function archiveMessage(messageId) {
    var el = document.getElementById('inbox-msg-' + messageId);
    if (!el) return;

    el.classList.add('inbox-removing');

    fetch('/inbox/', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent('<?= htmlspecialchars($csrf_token) ?>') +
              '&inbox_action=archive&message_id=' + messageId
    }).then(function(res) {
        if (res.ok) {
            setTimeout(function() { el.remove(); }, 350);
        } else {
            el.classList.remove('inbox-removing');
            alert('Archive failed. Please try again.');
        }
    }).catch(function() {
        el.classList.remove('inbox-removing');
        alert('Network error. Please try again.');
    });
}

document.querySelectorAll('.utc-time').forEach(function(el) {
    var utc = el.dataset.utc;
    if (!utc) return;
    var d = new Date(utc + 'Z');
    var mon = d.toLocaleString('en-US', {month: 'short'});
    var day = d.getDate();
    var h = d.getHours() % 12 || 12;
    var min = String(d.getMinutes()).padStart(2, '0');
    var ampm = d.getHours() < 12 ? 'am' : 'pm';
    el.textContent = mon + ' ' + day + ' ' + h + ':' + min + ampm;
});
</script>
