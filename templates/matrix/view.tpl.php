<h1>My Decision Matrix</h1>

<div id="passphrase-gate" style="max-width: 500px; margin: 2em auto;">
    <label for="passphrase" style="display: block; margin-bottom: 0.5em; font-weight: bold;">Enter your passphrase to view your matrix</label>
    <input type="password" id="passphrase"
           style="width: 100%; padding: 0.7em; font-size: 1em; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-panel); color: var(--text-primary); margin-bottom: 0.5em;">
    <button type="button" id="unlock-btn" class="btn btn-secondary" onclick="unlockMatrix()">Unlock</button>
    <p id="pass-error" style="color: var(--danger); display: none;">Wrong passphrase.</p>
</div>

<div id="matrix-content" style="display: none;">
    <div id="matrix-entries"></div>

    <div style="text-align: center; margin-top: 2em;">
        <a href="/matrix/add_problem.php" class="btn btn-primary">Add Another Problem</a>
    </div>
</div>

<script src="/js/dm_crypto.js"></script>
<script>
const VERIFY_BLOB = <?= json_encode($verify_blob) ?>;
const PROBLEMS = <?= $problems_json ?>;

async function unlockMatrix() {
    const p = document.getElementById('passphrase').value;
    const ok = await DM.verify(p, VERIFY_BLOB);
    if (!ok) {
        document.getElementById('pass-error').style.display = 'block';
        return;
    }

    document.getElementById('passphrase-gate').style.display = 'none';
    document.getElementById('matrix-content').style.display = 'block';

    const container = document.getElementById('matrix-entries');

    if (PROBLEMS.length === 0) {
        container.innerHTML = '<div class="PagePanel"><p>Your matrix is empty. Add your first problem to get started.</p></div>';
        return;
    }

    for (const prob of PROBLEMS) {
        const div = document.createElement('div');
        div.className = 'PagePanel';
        div.style.marginBottom = '1.5em';

        const problemText = await DM.decrypt(p, prob.problem);

        let html = '<p><strong>Limiting belief:</strong> <span style="color: var(--danger);">' + escapeHtml(problemText) + '</span></p>';

        if (prob.opposite) {
            const oppositeText = await DM.decrypt(p, prob.opposite.opposite);
            html += '<p><strong>Empowering opposite:</strong> <span style="color: var(--success);">' + escapeHtml(oppositeText) + '</span></p>';

            if (prob.evidence.length > 0) {
                html += '<p><strong>Evidence:</strong></p><ul>';
                for (const ev of prob.evidence) {
                    const evText = await DM.decrypt(p, ev.evidence);
                    html += '<li>' + escapeHtml(evText) + '</li>';
                }
                html += '</ul>';
                html += '<a href="/matrix/add_evidence.php?dm_mo=' + prob.opposite.dm_mo + '" style="font-size: 0.9em;">+ Add more evidence</a>';
            } else {
                html += '<p><em>No evidence yet.</em> <a href="/matrix/add_evidence.php?dm_mo=' + prob.opposite.dm_mo + '">Add evidence</a></p>';
            }
        } else {
            html += '<p><em>No opposite yet.</em> <a href="/matrix/add_opposite.php?dm_mp=' + prob.dm_mp + '">Add the opposite</a></p>';
        }

        div.innerHTML = html;
        container.appendChild(div);
    }
}

document.getElementById('passphrase').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); unlockMatrix(); }
});

function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}
</script>
