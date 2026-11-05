<?php

/**
 * @var int $jf_id
 * @var string $text
 * @var string $path
 * @var array{text: string, kind: string} $label
 * @var bool $can_rename
 * @var bool $has_edit
 */

include __DIR__ . '/journal_style.tpl.php';
?>
<div class="journal">
    <header class="journal-head">
        <a class="journal-back" href="/journal/">‹ Journal</a>
        <span id="journal-pill" class="journal-pill journal-pill-<?= htmlspecialchars($label['kind']) ?>">
            <?= htmlspecialchars($label['text']) ?>
        </span>
    </header>

    <div id="journal-messages" aria-live="polite"></div>

    <label for="journal-path">Filename</label>
    <div class="journal-row">
        <input type="text" id="journal-path" value="<?= htmlspecialchars($path) ?>"
            autocomplete="off" autocapitalize="off" spellcheck="false" <?= $can_rename ? '' : 'readonly' ?>>
        <?php if ($can_rename) : ?>
            <button type="button" id="journal-suggest">Use title</button>
        <?php endif; ?>
    </div>
    <?php if (!$can_rename) : ?>
        <span class="journal-muted">Committed files keep their name.</span>
    <?php endif; ?>

    <label for="journal-text">File</label>
    <textarea id="journal-text" spellcheck="true"><?= htmlspecialchars($text) ?></textarea>

    <div class="journal-row">
        <button type="button" class="journal-save" id="journal-save">Save</button>
        <a class="journal-back" href="/journal/">Cancel</a>
        <?php if ($has_edit) : ?>
            <button type="button" id="journal-discard">Discard my edit</button>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var url = '/journal/edit.php?jf_id=<?= (int) $jf_id ?>';
    var text = document.getElementById('journal-text');
    var pathInput = document.getElementById('journal-path');
    var messages = document.getElementById('journal-messages');
    var saveBtn = document.getElementById('journal-save');
    var pill = document.getElementById('journal-pill');

    function base64(s) {
        var bytes = new TextEncoder().encode(s);
        var bin = '';
        for (var i = 0; i < bytes.length; i += 0x8000) {
            bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        }
        return btoa(bin);
    }

    function show(kind, heading, items) {
        messages.innerHTML = '';
        var box = document.createElement('div');
        box.className = 'journal-banner journal-banner-' + kind;
        box.textContent = heading;
        if (items && items.length) {
            var ul = document.createElement('ul');
            items.forEach(function (item) {
                var li = document.createElement('li');
                li.textContent = item;
                ul.appendChild(li);
            });
            box.appendChild(ul);
        }
        messages.appendChild(box);
    }

    function send(body) {
        saveBtn.disabled = true;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().catch(function () {
                return { ok: false, problems: ['mg answered with an error (' + r.status + '). Your text is still here.'] };
            });
        }).catch(function () {
            return { ok: false, problems: ['Could not reach mg. Your text is still here; try Save again.'] };
        }).finally(function () {
            saveBtn.disabled = false;
        });
    }

    saveBtn.addEventListener('click', function () {
        send({ action: 'save', content: base64(text.value), path: pathInput.value.trim() }).then(function (res) {
            if (!res.ok) {
                show('bad', 'Not saved.', res.problems || []);
                return;
            }
            if (res.unchanged) {
                show('ok', 'No changes from what Lemur 13 has, so nothing will be sent.', res.warnings || []);
                pill.textContent = 'No edit waiting';
            } else {
                show(res.warnings && res.warnings.length ? 'warn' : 'ok',
                    'Saved. Lemur 13 will pick this up the next time Carrie runs.', res.warnings || []);
                pill.textContent = 'Your edit · waiting for Lemur 13';
                pill.className = 'journal-pill journal-pill-edit';
            }
        });
    });

    var discard = document.getElementById('journal-discard');
    if (discard) {
        discard.addEventListener('click', function () {
            if (!window.confirm('Discard your edit and go back to what Lemur 13 has?')) {
                return;
            }
            send({ action: 'discard' }).then(function (res) {
                if (res.ok) {
                    location.reload();
                } else {
                    show('bad', 'Not discarded.', res.problems || []);
                }
            });
        });
    }

    var suggest = document.getElementById('journal-suggest');
    if (suggest) {
        suggest.addEventListener('click', function () {
            var head = text.value.replace(/\r\n?/g, '\n').split('\n---\n')[0];
            var title = (head.match(/^title:\s*["']?(.*?)["']?\s*$/m) || [])[1] || '';
            var date = (head.match(/^date:\s*(\d{4})-(\d{2})-(\d{2})/m) || []);
            var wasDream = /dream/i.test(pathInput.value.split('/').pop());
            var slug = title.replace(/^dream:\s*/i, '').toLowerCase()
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60).replace(/-+$/, '');
            if (!slug) {
                show('bad', 'Give the file a title first.');
                return;
            }
            if (wasDream && slug.indexOf('dream') === -1) {
                slug = 'dream-' + slug;
            }
            var day = date.length === 4 ? date[1] + '/' + date[2] + '/' + date[3] : pathInput.value.slice(0, 10);
            pathInput.value = day + slug + '.md';
        });
    }
})();
</script>
