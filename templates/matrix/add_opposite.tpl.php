<h1>Step 2: Decide on the Empowering Opposite</h1>

<div class="PagePanel">
    <p>Now take that limiting belief and flip it. What is the <strong>empowering opposite</strong>?
    This is what you <em>choose</em> to believe instead.</p>
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
        <p style="font-weight: bold;">Your limiting belief:</p>
        <p id="problem-display" style="font-style: italic;"></p>
    </div>

    <form id="opposite-form">
        <div style="margin-bottom: 1.5em;">
            <label for="opposite-text" style="display: block; margin-bottom: 0.5em; font-weight: bold;">The empowering opposite:</label>
            <textarea id="opposite-text" rows="3" required
                      style="width: 100%; padding: 0.7em; font-size: 1em; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-panel); color: var(--text-primary);"></textarea>
        </div>
        <button type="submit" class="btn btn-primary" id="submit-btn">Next: Find Evidence</button>
    </form>
</div>

<script src="/js/dm_crypto.js"></script>
<script>
const VERIFY_BLOB = <?= json_encode($verify_blob) ?>;
const PROBLEM_BLOB = <?= json_encode($problem_blob) ?>;
let PASSPHRASE = '';

async function unlockForm() {
    const p = document.getElementById('passphrase').value;
    const ok = await DM.verify(p, VERIFY_BLOB);
    if (ok) {
        PASSPHRASE = p;
        document.getElementById('passphrase-gate').style.display = 'none';
        document.getElementById('unlocked-content').style.display = 'block';

        // Decrypt and show the problem
        const problemText = await DM.decrypt(p, PROBLEM_BLOB);
        document.getElementById('problem-display').textContent = problemText;
    } else {
        document.getElementById('pass-error').style.display = 'block';
    }
}

document.getElementById('passphrase').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); unlockForm(); }
});

document.getElementById('opposite-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const text = document.getElementById('opposite-text').value.trim();
    if (!text) return;

    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.textContent = 'Encrypting...';

    const encrypted = await DM.encrypt(PASSPHRASE, text);

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/matrix/add_opposite.php?dm_mp=<?= $dm_mp ?>';
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'encrypted_opposite';
    input.value = encrypted;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
});
</script>
