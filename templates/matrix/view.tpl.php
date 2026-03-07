<h1>My Decision Matrix</h1>

<div id="passphrase-gate" style="max-width: 500px; margin: 2em auto;">
    <label for="passphrase" style="display: block; margin-bottom: 0.5em; font-weight: bold;">Enter your passphrase to view your matrix</label>
    <input type="password" id="passphrase"
           style="width: 100%; padding: 0.7em; font-size: 1em; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-panel); color: var(--text-primary); margin-bottom: 0.5em;">
    <button type="button" id="unlock-btn" class="btn btn-secondary" onclick="unlockMatrix()">Unlock</button>
    <p id="pass-error" style="color: var(--danger); display: none;">Wrong passphrase.</p>
</div>

<div id="matrix-content" style="display: none;">
    <div style="overflow-x: auto;">
        <table id="matrix-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="padding: 0.75em; text-align: left; border-bottom: 2px solid var(--border-color); background: var(--bg-panel); color: var(--danger);">Problem</th>
                    <th style="padding: 0.75em; text-align: left; border-bottom: 2px solid var(--border-color); background: var(--bg-panel); color: var(--success);">Opposite</th>
                    <th style="padding: 0.75em; text-align: left; border-bottom: 2px solid var(--border-color); background: var(--bg-panel);">Evidence</th>
                </tr>
            </thead>
            <tbody id="matrix-body"></tbody>
        </table>
    </div>

    <div style="text-align: center; margin-top: 2em;">
        <a href="/matrix/add_problem.php" class="btn btn-primary">Add Another Problem</a>
    </div>
</div>

<script src="/js/dm_crypto.js"></script>
<script>
const VERIFY_BLOB = <?= json_encode($verify_blob) ?>;
const PROBLEMS = <?= $problems_json ?>;

async function unlockMatrix(passphrase) {
    const p = passphrase || document.getElementById('passphrase').value;
    const ok = await DM.verify(p, VERIFY_BLOB);
    if (!ok) {
        DM.clearPassphrase();
        document.getElementById('pass-error').style.display = 'block';
        return;
    }
    DM.cachePassphrase(p);

    document.getElementById('passphrase-gate').style.display = 'none';
    document.getElementById('matrix-content').style.display = 'block';

    const tbody = document.getElementById('matrix-body');

    if (PROBLEMS.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" style="padding: 1em; text-align: center;">Your matrix is empty. Add your first problem to get started.</td></tr>';
        return;
    }

    const cellStyle = 'padding: 0.75em; border-bottom: 1px solid var(--border-color); vertical-align: top;';

    for (const prob of PROBLEMS) {
        const tr = document.createElement('tr');

        // Problem cell
        const problemText = await DM.decrypt(p, prob.problem);
        const tdProblem = document.createElement('td');
        tdProblem.style.cssText = cellStyle;
        tdProblem.textContent = problemText;
        tr.appendChild(tdProblem);

        // Opposite cell
        const tdOpposite = document.createElement('td');
        tdOpposite.style.cssText = cellStyle;
        if (prob.opposite) {
            const oppositeText = await DM.decrypt(p, prob.opposite.opposite);
            tdOpposite.textContent = oppositeText;
        } else {
            tdOpposite.innerHTML = '<a href="/matrix/add_opposite.php?dm_mp=' + prob.dm_mp + '">+ Add opposite</a>';
        }
        tr.appendChild(tdOpposite);

        // Evidence cell
        const tdEvidence = document.createElement('td');
        tdEvidence.style.cssText = cellStyle;
        if (prob.opposite && prob.evidence.length > 0) {
            const ul = document.createElement('ul');
            ul.style.cssText = 'margin: 0; padding-left: 1.2em;';
            for (const ev of prob.evidence) {
                const li = document.createElement('li');
                li.textContent = await DM.decrypt(p, ev.evidence);
                ul.appendChild(li);
            }
            tdEvidence.appendChild(ul);
            const addLink = document.createElement('a');
            addLink.href = '/matrix/add_evidence.php?dm_mo=' + prob.opposite.dm_mo;
            addLink.textContent = '+ Add more';
            addLink.style.fontSize = '0.9em';
            tdEvidence.appendChild(addLink);
        } else if (prob.opposite) {
            tdEvidence.innerHTML = '<a href="/matrix/add_evidence.php?dm_mo=' + prob.opposite.dm_mo + '">+ Add evidence</a>';
        } else {
            tdEvidence.textContent = '—';
        }
        tr.appendChild(tdEvidence);

        tbody.appendChild(tr);
    }
}

document.getElementById('passphrase').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); unlockMatrix(); }
});

// Auto-unlock if passphrase is cached
const cached = DM.getCachedPassphrase();
if (cached) unlockMatrix(cached);
</script>
