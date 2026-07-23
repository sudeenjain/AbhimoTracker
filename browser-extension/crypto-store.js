'use strict';

/**
 * Encrypts the one genuinely sensitive thing this extension has to persist
 * -- the employee's password, kept so getAuthToken() can silently refresh
 * an expired JWT without prompting a re-login every ~8h. Previously stored
 * as a plain string inside the same chrome.storage.local blob as
 * everything else; that's readable by anything with disk access to the
 * browser profile (forensics, malware, a stolen backup), unlike
 * desktop-tray/credential-store.js's AES-256-GCM-encrypted file.
 *
 * Brings the extension to the same protection level using the Web Crypto
 * API (no npm dependency available to a MV3 service worker):
 *   - A random 256-bit AES-GCM key is generated once and stored in
 *     chrome.storage.local under its own key, analogous to
 *     credential-store.js's tray.key file.
 *   - The password is encrypted with a fresh random IV every time it's
 *     saved and stored as { iv, data }, both base64.
 *
 * Same trade-off credential-store.js documents for itself: the key sits in
 * the same storage as the ciphertext, so this defends against casual
 * inspection and offline/disk-level reading of the profile, not against an
 * attacker who can already execute code as this browser profile's user --
 * that threat model would need OS-native secure storage, which isn't
 * reachable from a MV3 service worker without a native-messaging host.
 */

const KEY_STORAGE_KEY = 'abhimoCryptoKey';

function bufToBase64(buf) {
  return btoa(String.fromCharCode(...new Uint8Array(buf)));
}

function base64ToBuf(b64) {
  const binary = atob(b64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}

/** @returns {Promise<CryptoKey>} */
async function getOrCreateKey() {
  const stored = await chrome.storage.local.get(KEY_STORAGE_KEY);
  let rawB64 = stored[KEY_STORAGE_KEY];

  if (!rawB64) {
    const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
    const exported = await crypto.subtle.exportKey('raw', key);
    rawB64 = bufToBase64(exported);
    await chrome.storage.local.set({ [KEY_STORAGE_KEY]: rawB64 });
  }

  return crypto.subtle.importKey('raw', base64ToBuf(rawB64), { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
}

/**
 * @param {string} plaintext
 * @returns {Promise<{iv: string, data: string}>} base64-encoded IV and
 *   ciphertext (AES-GCM's auth tag is appended to the ciphertext by
 *   WebCrypto automatically -- no separate tag field needed, unlike the
 *   Node crypto module credential-store.js uses on the tray side).
 */
export async function encryptSecret(plaintext) {
  const key = await getOrCreateKey();
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const encoded = new TextEncoder().encode(plaintext);
  const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, encoded);
  return { iv: bufToBase64(iv), data: bufToBase64(ciphertext) };
}

/**
 * @param {{iv: string, data: string}|null|undefined} enc
 * @returns {Promise<string|null>} null if enc is empty or decryption fails
 *   (corrupt/tampered storage) -- callers treat that the same as "not
 *   signed in" rather than throwing, matching credential-store.js's
 *   load() behavior on the tray side.
 */
export async function decryptSecret(enc) {
  if (!enc || !enc.iv || !enc.data) return null;
  try {
    const key = await getOrCreateKey();
    const plaintextBuf = await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: new Uint8Array(base64ToBuf(enc.iv)) },
      key,
      base64ToBuf(enc.data)
    );
    return new TextDecoder().decode(plaintextBuf);
  } catch (err) {
    console.error('Abhimo Tracker: failed to decrypt stored password:', err.message);
    return null;
  }
}
