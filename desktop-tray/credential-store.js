'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

/**
 * Simple local encrypted file store for the paired username/password, per
 * project decision: no OS keychain (Credential Manager / keytar) -- just an
 * encrypted file the tray app manages itself, in Electron's per-user
 * userData directory.
 *
 * Two files live there:
 *   tray.key         -- random 32-byte key, generated once on first pairing.
 *   credentials.enc   -- { iv, tag, data } (all base64), AES-256-GCM.
 *
 * This protects against casual inspection (opening the file in a text
 * editor, a backup landing somewhere it shouldn't) but the key sits on the
 * same disk as the ciphertext, restricted only by OS file permissions --
 * it is not equivalent to OS-native secure storage (Windows Credential
 * Manager, macOS Keychain). That trade-off was made deliberately to avoid
 * the extra native-module dependency (keytar) that OS-native storage needs.
 */
class CredentialStore {
    constructor(userDataDir) {
        this.keyPath = path.join(userDataDir, 'tray.key');
        this.credsPath = path.join(userDataDir, 'credentials.enc');
    }

    _getOrCreateKey() {
        if (fs.existsSync(this.keyPath)) {
            return fs.readFileSync(this.keyPath);
        }
        const key = crypto.randomBytes(32);
        fs.writeFileSync(this.keyPath, key, { mode: 0o600 });
        try {
            fs.chmodSync(this.keyPath, 0o600);
        } catch (_) {
            // chmod is a no-op on Windows; ignore.
        }
        return key;
    }

    /** @param {{username: string, password: string, apiBase: string}} creds */
    save(creds) {
        const key = this._getOrCreateKey();
        const iv = crypto.randomBytes(12);
        const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
        const plaintext = Buffer.from(JSON.stringify(creds), 'utf8');
        const encrypted = Buffer.concat([cipher.update(plaintext), cipher.final()]);
        const tag = cipher.getAuthTag();

        const payload = {
            iv: iv.toString('base64'),
            tag: tag.toString('base64'),
            data: encrypted.toString('base64'),
        };
        fs.writeFileSync(this.credsPath, JSON.stringify(payload), { mode: 0o600 });
        try {
            fs.chmodSync(this.credsPath, 0o600);
        } catch (_) {
            // no-op on Windows
        }
    }

    /** @returns {{username: string, password: string, apiBase: string} | null} */
    load() {
        if (!fs.existsSync(this.credsPath) || !fs.existsSync(this.keyPath)) {
            return null;
        }
        try {
            const key = fs.readFileSync(this.keyPath);
            const payload = JSON.parse(fs.readFileSync(this.credsPath, 'utf8'));
            const decipher = crypto.createDecipheriv('aes-256-gcm', key, Buffer.from(payload.iv, 'base64'));
            decipher.setAuthTag(Buffer.from(payload.tag, 'base64'));
            const decrypted = Buffer.concat([
                decipher.update(Buffer.from(payload.data, 'base64')),
                decipher.final(),
            ]);
            return JSON.parse(decrypted.toString('utf8'));
        } catch (err) {
            // Corrupt or tampered file -- treat as unpaired rather than crashing.
            console.error('Failed to decrypt stored credentials:', err.message);
            return null;
        }
    }

    clear() {
        for (const p of [this.credsPath, this.keyPath]) {
            if (fs.existsSync(p)) fs.unlinkSync(p);
        }
    }
}

module.exports = { CredentialStore };