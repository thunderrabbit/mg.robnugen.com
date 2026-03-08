/**
 * Decision Matrix client-side encryption using Web Crypto API.
 * Format: salt(16) + iv(12) + ciphertext
 */
const DM = {
    VERIFY_STRING: 'DECISION_MATRIX_OK',

    async deriveKey(passphrase, salt) {
        const enc = new TextEncoder();
        const keyMaterial = await crypto.subtle.importKey(
            'raw', enc.encode(passphrase), 'PBKDF2', false, ['deriveKey']
        );
        return crypto.subtle.deriveKey(
            { name: 'PBKDF2', salt, iterations: 600000, hash: 'SHA-256' },
            keyMaterial,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt', 'decrypt']
        );
    },

    async encrypt(passphrase, plaintext) {
        const enc = new TextEncoder();
        const salt = crypto.getRandomValues(new Uint8Array(16));
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const key = await this.deriveKey(passphrase, salt);
        const ct = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv }, key, enc.encode(plaintext)
        );
        const blob = new Uint8Array(salt.length + iv.length + ct.byteLength);
        blob.set(salt, 0);
        blob.set(iv, 16);
        blob.set(new Uint8Array(ct), 28);
        return this.toBase64(blob);
    },

    async decrypt(passphrase, base64Blob) {
        const dec = new TextDecoder();
        const blob = this.fromBase64(base64Blob);
        const salt = blob.slice(0, 16);
        const iv = blob.slice(16, 28);
        const ct = blob.slice(28);
        const key = await this.deriveKey(passphrase, salt);
        const pt = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv }, key, ct
        );
        return dec.decode(pt);
    },

    async verify(passphrase, base64Blob) {
        try {
            const result = await this.decrypt(passphrase, base64Blob);
            return result === this.VERIFY_STRING;
        } catch {
            return false;
        }
    },

    cachePassphrase(passphrase) {
        sessionStorage.setItem('dm_pass', passphrase);
    },

    getCachedPassphrase() {
        return sessionStorage.getItem('dm_pass');
    },

    clearPassphrase() {
        sessionStorage.removeItem('dm_pass');
    },

    toBase64(bytes) {
        let binary = '';
        for (const b of bytes) binary += String.fromCharCode(b);
        return btoa(binary);
    },

    fromBase64(str) {
        const binary = atob(str);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes;
    }
};
