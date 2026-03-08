<h1>Choose Your Passphrase</h1>

<div class="PagePanel">
    <p>Your Decision Matrix is encrypted with a passphrase that only you know.
    The server never sees your passphrase or your plaintext thoughts.</p>

    <p style="color: var(--warning); font-weight: bold;">
        If you lose your passphrase, your data cannot be recovered. There is no reset.
    </p>
</div>

<form id="setup-form" style="max-width: 400px; margin: 2em auto;">
    <div style="margin-bottom: 1em;">
        <label for="passphrase" style="display: block; margin-bottom: 0.5em; font-weight: bold;">Passphrase</label>
        <input type="password" id="passphrase" required
               style="width: 100%; padding: 0.7em; font-size: 1em; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-panel); color: var(--text-primary);">
    </div>
    <div style="margin-bottom: 1.5em;">
        <label for="passphrase2" style="display: block; margin-bottom: 0.5em; font-weight: bold;">Confirm Passphrase</label>
        <input type="password" id="passphrase2" required
               style="width: 100%; padding: 0.7em; font-size: 1em; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-panel); color: var(--text-primary);">
    </div>
    <p id="error-msg" style="color: var(--danger); display: none;"></p>
    <button type="submit" class="btn btn-primary" id="submit-btn">Create My Matrix</button>
</form>

<script src="/js/dm_crypto.js"></script>
<script>
document.getElementById('setup-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const p1 = document.getElementById('passphrase').value;
    const p2 = document.getElementById('passphrase2').value;
    const errEl = document.getElementById('error-msg');
    const btn = document.getElementById('submit-btn');

    if (p1 !== p2) {
        errEl.textContent = 'Passphrases do not match.';
        errEl.style.display = 'block';
        return;
    }
    if (p1.length < 4) {
        errEl.textContent = 'Passphrase must be at least 4 characters.';
        errEl.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Encrypting...';
    errEl.style.display = 'none';

    const verifyBlob = await DM.encrypt(p1, DM.VERIFY_STRING);
    DM.cachePassphrase(p1);

    // Submit via hidden form
    const form = document.createElement('form');
    form.method = 'POST';
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'verify_blob';
    input.value = verifyBlob;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
});
</script>
