<div class="dashboard-container">
    <header class="dashboard-header">
        <a href="/exterm/?project_id=<?= (int)$item['project_id'] ?>" class="btn-sm btn-back">← <?= htmlspecialchars($item['project_name']) ?></a>
        <h1><?= htmlspecialchars($item['title']) ?></h1>
        <?php if ($item['can_write']): ?>
            <a href="/exterm/edit.php?exterm_item_id=<?= (int)$item['exterm_item_id'] ?>" class="btn-sm">Edit</a>
        <?php endif; ?>
    </header>

    <?php if (!empty($msg)): ?>
        <div class="alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="card">
        <dl class="meta-list">
            <dt>Kind</dt>    <dd><?= htmlspecialchars($item['kind']) ?></dd>
            <dt>Status</dt>  <dd><span class="status-badge status-<?= htmlspecialchars($item['status']) ?>"><?= htmlspecialchars($item['status']) ?></span></dd>
            <dt>Risk</dt>    <dd><?= htmlspecialchars($item['risk']) ?></dd>
            <dt>Author</dt>  <dd><?= htmlspecialchars($item['author_name'] ?? '—') ?></dd>
            <dt>Assignee</dt><dd><?= htmlspecialchars($item['assignee_name'] ?? '—') ?></dd>
            <dt>Created</dt> <dd><?= htmlspecialchars(substr($item['created_at_utc'], 0, 16)) ?> UTC</dd>
            <dt>Updated</dt> <dd><?= htmlspecialchars(substr($item['updated_at_utc'], 0, 16)) ?> UTC</dd>
            <?php if ($item['done_at_utc']): ?>
                <dt>Closed</dt><dd><?= htmlspecialchars(substr($item['done_at_utc'], 0, 16)) ?> UTC</dd>
            <?php endif; ?>
        </dl>
    </div>

    <?php if (!empty($item['body'])): ?>
        <div class="card">
            <pre style="white-space:pre-wrap; word-break:break-word;"><?= htmlspecialchars($item['body']) ?></pre>
        </div>
    <?php endif; ?>

    <?php if ($item['can_write'] && $item['status'] === 'needs_approval'): ?>
        <div class="card">
            <h3 style="margin-top:0;">Awaiting Approval</h3>
            <p>This is an <strong>irreversible</strong> task waiting for human sign-off before the agent proceeds.</p>
            <form method="POST" action="/exterm/view.php?exterm_item_id=<?= (int)$item['exterm_item_id'] ?>" style="display:flex;gap:1rem;">
                <button type="submit" name="action" value="approve" class="btn-primary">Approve</button>
                <button type="submit" name="action" value="reject"  class="btn-danger">Reject</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($item['can_write']): ?>
        <div class="card">
            <form method="POST" action="/exterm/view.php?exterm_item_id=<?= (int)$item['exterm_item_id'] ?>"
                  onsubmit="return confirm('Delete this item permanently?')">
                <button type="submit" name="action" value="delete" class="btn-danger btn-sm">Delete item</button>
            </form>
        </div>
    <?php endif; ?>
</div>
