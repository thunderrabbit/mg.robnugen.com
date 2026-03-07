<h1>Step 3: Find the Evidence</h1>

<div class="PagePanel">
    <p>Now find <strong>real evidence</strong> from your life that the empowering belief is true.
    This is what makes it stick. Add as many pieces of evidence as you can.</p>
</div>

<div id="passphrase-gate" style="max-width: 500px; margin: 2em auto;">
    <label for="passphrase" style="display: block; margin-bottom: 0.5em; font-weight: bold;">Enter your passphrase</label>
    <input type="password" id="passphrase"
           style="width: 100%; padding: 0.7em; font-size: 1em; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-panel); color: var(--text-primary); margin-bottom: 0.5em;">
    <button type="button" id="unlock-btn" class="btn btn-secondary" onclick="unlockForm()">Unlock</button>
    <p id="pass-error" style="color: var(--danger); display: none;">Wrong passphrase.</p>
</div>

<div id="unlocked-content" style="max-width: 500px; margin: 2em auto; display: none;">
    <div class="PagePanel">
        <p><strong>Limiting belief:</strong> <span id="problem-display" style="font-style: italic;"></span></p>
        <p><strong>Empowering opposite:</strong> <span id="opposite-display" style="font-style: italic;"></span></p>
    </div>

    <?php if ($evidence_count > 0): ?>
    <p style="color: var(--success);">You have <?= $evidence_count ?> piece<?= $evidence_count > 1 ? 's' : '' ?> of evidence so far.</p>
    <?php endif; ?>

    <form id="evidence-form">
        <div style="margin-bottom: 1.5em;">
            <label for="evidence-text" style="display: block; margin-bottom: 0.5em; font-weight: bold;">Evidence that the opposite is true:</label>
            <textarea id="evidence-text" rows="3" required
                      style="width: 100%; padding: 0.7em; font-size: 1em; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-panel); color: var(--text-primary);"></textarea>
        </div>
        <div style="display: flex; gap: 1em; flex-wrap: wrap;">
            <button type="submit" class="btn btn-secondary" id="another-btn" data-action="another">Save &amp; Add Another</button>
            <button type="submit" class="btn btn-primary" id="done-btn" data-action="done">Save &amp; View Matrix</button>
        </div>
    </form>
</div>

<script src="/js/dm_crypto.js"></script>
<script>
const VERIFY_BLOB = <?= json_encode($verify_blob) ?>;
const PROBLEM_BLOB = <?= json_encode($problem_blob) ?>;
const OPPOSITE_BLOB = <?= json_encode($opposite_blob) ?>;
let PASSPHRASE = '';

async function unlockForm(passphrase) {
    const p = passphrase || document.getElementById('passphrase').value;
    const ok = await DM.verify(p, VERIFY_BLOB);
    if (ok) {
        PASSPHRASE = p;
        DM.cachePassphrase(p);
        document.getElementById('passphrase-gate').style.display = 'none';
        document.getElementById('unlocked-content').style.display = 'block';

        document.getElementById('problem-display').textContent = await DM.decrypt(p, PROBLEM_BLOB);
        document.getElementById('opposite-display').textContent = await DM.decrypt(p, OPPOSITE_BLOB);
    } else {
        DM.clearPassphrase();
        document.getElementById('pass-error').style.display = 'block';
    }
}

document.getElementById('passphrase').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); unlockForm(); }
});

const cached = DM.getCachedPassphrase();
if (cached) unlockForm(cached);

let submitAction = 'done';
document.querySelectorAll('#evidence-form button[type="submit"]').forEach(function(btn) {
    btn.addEventListener('click', function() { submitAction = this.dataset.action; });
});

document.getElementById('evidence-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const text = document.getElementById('evidence-text').value.trim();
    if (!text) return;

    document.getElementById('another-btn').disabled = true;
    document.getElementById('done-btn').disabled = true;

    const encrypted = await DM.encrypt(PASSPHRASE, text);

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/matrix/add_evidence.php?dm_mo=<?= $dm_mo ?>';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'encrypted_evidence';
    input.value = encrypted;
    form.appendChild(input);

    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = submitAction;
    form.appendChild(actionInput);

    document.body.appendChild(form);
    form.submit();
});
</script>
