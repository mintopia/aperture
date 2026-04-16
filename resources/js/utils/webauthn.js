/**
 * WebAuthn base64/buffer conversion helpers and CSRF token utility.
 *
 * These functions are shared between Login.vue (passkey authentication)
 * and Account/Settings.vue (passkey registration/management).
 */

/**
 * Decode a base64url-encoded string into an ArrayBuffer.
 *
 * Handles URL-safe alphabet (`-` → `+`, `_` → `/`) and adds any
 * padding (`=`) stripped during encoding so `atob()` can decode it.
 *
 * @param {string} base64url - Base64url-encoded string (RFC 4648 §5)
 * @returns {ArrayBuffer} Decoded binary data
 */
export function base64UrlToBuffer(base64url) {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    const pad = base64.length % 4;
    const padded = pad ? base64 + '='.repeat(4 - pad) : base64;
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
}

/**
 * Encode an ArrayBuffer as a base64url string.
 *
 * Produces URL-safe output (`+` → `-`, `/` → `_`) with trailing
 * padding stripped, matching the format WebAuthn servers expect.
 *
 * @param {ArrayBuffer} buffer - Binary data to encode
 * @returns {string} Base64url-encoded string (no padding)
 */
export function bufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/**
 * Read the CSRF token from the page's `<meta name="csrf-token">` tag.
 *
 * @returns {string} The token value, or an empty string when the tag is absent.
 */
export function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}
