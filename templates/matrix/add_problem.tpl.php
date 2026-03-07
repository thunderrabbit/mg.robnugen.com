<h1>Step 1: Identify the Limiting Belief</h1>

<div class="PagePanel">
    <p>What is a thought or belief that holds you back? Something you tell yourself
    that keeps you stuck.</p>
    <p><em>Examples: "I'm not good enough," "I'll never succeed," "I don't deserve happiness"</em></p>
</div>

<div id="passphrase-gate" style="max-width: 500px; margin: 2em auto;">
    <label for="passphrase" style="display: block; margin-bottom: 0.5em; font-weight: bold;">Enter your passphrase</label>
    <input type="password" id="passphrase"
           style="width: 100%; padding: 0.7em; font-size: 1em; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-panel); color: var(--text-primary); margin-bottom: 0.5em;">
    <button type="button" id="unlock-btn" class="btn btn-secondary" onclick="unlockForm()">Unlock</button>
    <p id="pass-error" style="color: var(--danger); display: none;">Wrong passphrase.</p>
</div>

<form id="problem-form" style="max-width: 500px; margin: 2em auto; display: none;">
    <div style="margin-bottom: 1.5em;">
        <label for="problem-text" style="display: block; margin-bottom: 0.5em; font-weight: bold;">The limiting belief:</label>
        <textarea id="problem-text" rows="3" required
                  style="width: 100%; padding: 0.7em; font-size: 1em; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-panel); color: var(--text-primary);"></textarea>
    </div>
    <button type="submit" class="btn btn-primary" id="submit-btn">Next: Find the Opposite</button>
</form>

<script src="/js/dm_crypto.js"></script>
<script>
const VERIFY_BLOB = <?= json_encode($verify_blob) ?>;
let PASSPHRASE = '';

async function unlockForm() {
    const p = document.getElementById('passphrase').value;
    const ok = await DM.verify(p, VERIFY_BLOB);
    if (ok) {
        PASSPHRASE = p;
        document.getElementById('passphrase-gate').style.display = 'none';
        document.getElementById('problem-form').style.display = 'block';
    } else {
        document.getElementById('pass-error').style.display = 'block';
    }
}

document.getElementById('passphrase').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); unlockForm(); }
});

document.getElementById('problem-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const text = document.getElementById('problem-text').value.trim();
    if (!text) return;

    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.textContent = 'Encrypting...';

    const encrypted = await DM.encrypt(PASSPHRASE, text);

    const form = document.createElement('form');
    form.method = 'POST';
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'encrypted_problem';
    input.value = encrypted;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
});
</script>
