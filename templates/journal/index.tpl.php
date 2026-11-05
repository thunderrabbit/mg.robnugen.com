<?php

/**
 * @var array<string, list<array{jf_id: int, title: string, path: string, label: array{text: string, kind: string}}>> $days
 * @var string|null $last_synced UTC, as stored
 */

include __DIR__ . '/journal_style.tpl.php';
$count = 0;
foreach ($days as $files) {
    $count += count($files);
}
?>
<div class="journal">
    <header class="journal-head">
        <h1>Journal</h1>
        <span class="journal-muted"><?= $count ?> <?= $count === 1 ? 'file' : 'files' ?></span>
    </header>

    <p class="journal-muted journal-sync">
        <?php if ($last_synced === null) : ?>
            Lemur 13 has not synced yet.
        <?php else : ?>
            Lemur 13 last synced
            <time class="journal-localtime" datetime="<?= htmlspecialchars(str_replace(' ', 'T', $last_synced)) ?>Z">
                <?= htmlspecialchars(substr($last_synced, 0, 16)) ?> UTC
            </time>
        <?php endif; ?>
    </p>

    <?php if ($days === []) : ?>
        <p>No recent entries yet. Lemur 13 uploads entries that are not committed, or dated in the last 14 days,
            each time Carrie runs.</p>
    <?php endif; ?>

    <?php foreach ($days as $day => $files) : ?>
        <h2 class="journal-day"><?= htmlspecialchars($day) ?></h2>
        <?php foreach ($files as $file) : ?>
            <a class="journal-item" href="/journal/edit.php?jf_id=<?= (int) $file['jf_id'] ?>">
                <span class="journal-title"><?= htmlspecialchars($file['title']) ?></span>
                <span class="journal-path"><?= htmlspecialchars($file['path']) ?></span>
                <span class="journal-pill journal-pill-<?= htmlspecialchars($file['label']['kind']) ?>">
                    <?= htmlspecialchars($file['label']['text']) ?>
                </span>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
<script>
document.querySelectorAll('.journal-localtime').forEach(function (el) {
    var d = new Date(el.getAttribute('datetime'));
    if (!isNaN(d)) {
        el.textContent = d.toLocaleString(undefined, {
            weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
        });
    }
});
</script>
