import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { base64UrlToBuffer, bufferToBase64, getCsrfToken } from '@/utils/webauthn';

// ─── base64UrlToBuffer ──────────────────────────────────────────────────────

describe('base64UrlToBuffer', () => {
    it('decodes a simple base64url string', () => {
        // "Hello" in base64 is "SGVsbG8="
        // In base64url (no padding) it's "SGVsbG8"
        const buffer = base64UrlToBuffer('SGVsbG8');
        const bytes = new Uint8Array(buffer);
        expect(String.fromCharCode(...bytes)).toBe('Hello');
    });

    it('handles URL-safe characters (- and _)', () => {
        // Standard base64 with + and / characters
        // bytes [0xFB, 0xEF, 0xBE] → base64 "++++++" contains + → base64url uses -
        // Use a known test vector: 3 bytes [0x3E, 0x3F, 0xFF]
        // base64: "Pj//", base64url: "Pj__"
        const buffer = base64UrlToBuffer('Pj__');
        const bytes = new Uint8Array(buffer);
        expect(bytes[0]).toBe(0x3e);
        expect(bytes[1]).toBe(0x3f);
        expect(bytes[2]).toBe(0xff);
    });

    it('adds padding when length % 4 === 2', () => {
        // "A" in base64 is "QQ==" (2 padding chars needed)
        // base64url strips padding: "QQ"
        const buffer = base64UrlToBuffer('QQ');
        const bytes = new Uint8Array(buffer);
        expect(String.fromCharCode(...bytes)).toBe('A');
    });

    it('adds padding when length % 4 === 3', () => {
        // "AB" in base64 is "QUI=" (1 padding char needed)
        // base64url strips padding: "QUI"
        const buffer = base64UrlToBuffer('QUI');
        const bytes = new Uint8Array(buffer);
        expect(String.fromCharCode(...bytes)).toBe('AB');
    });

    it('handles input that needs no padding (length % 4 === 0)', () => {
        // "ABC" in base64 is "QUJD" (no padding needed)
        const buffer = base64UrlToBuffer('QUJD');
        const bytes = new Uint8Array(buffer);
        expect(String.fromCharCode(...bytes)).toBe('ABC');
    });

    it('returns an ArrayBuffer', () => {
        const buffer = base64UrlToBuffer('SGVsbG8');
        expect(buffer).toBeInstanceOf(ArrayBuffer);
    });

    it('handles empty string', () => {
        const buffer = base64UrlToBuffer('');
        expect(buffer.byteLength).toBe(0);
    });

    it('round-trips with bufferToBase64', () => {
        const original = 'dGVzdCBkYXRhIGZvciByb3VuZC10cmlw';
        const buffer = base64UrlToBuffer(original);
        const encoded = bufferToBase64(buffer);
        expect(encoded).toBe(original);
    });
});

// ─── bufferToBase64 ─────────────────────────────────────────────────────────

describe('bufferToBase64', () => {
    it('encodes a simple buffer to base64url', () => {
        const bytes = new Uint8Array([72, 101, 108, 108, 111]); // "Hello"
        const result = bufferToBase64(bytes.buffer);
        expect(result).toBe('SGVsbG8');
    });

    it('replaces + with - in output', () => {
        // Byte 0x3E → standard base64 has "+" → should become "-"
        const bytes = new Uint8Array([0x3e, 0x3f, 0xff]);
        const result = bufferToBase64(bytes.buffer);
        expect(result).not.toContain('+');
        expect(result).toBe('Pj__');
    });

    it('replaces / with _ in output', () => {
        const bytes = new Uint8Array([0x3e, 0x3f, 0xff]);
        const result = bufferToBase64(bytes.buffer);
        expect(result).not.toContain('/');
    });

    it('strips trailing padding', () => {
        // Single byte "A" → base64 "QQ==" → base64url "QQ"
        const bytes = new Uint8Array([65]);
        const result = bufferToBase64(bytes.buffer);
        expect(result).not.toContain('=');
        expect(result).toBe('QQ');
    });

    it('returns an empty string for an empty buffer', () => {
        const bytes = new Uint8Array([]);
        const result = bufferToBase64(bytes.buffer);
        expect(result).toBe('');
    });

    it('returns a string', () => {
        const bytes = new Uint8Array([1, 2, 3]);
        const result = bufferToBase64(bytes.buffer);
        expect(typeof result).toBe('string');
    });

    it('round-trips with base64UrlToBuffer', () => {
        const original = new Uint8Array([0, 1, 127, 128, 255]);
        const encoded = bufferToBase64(original.buffer);
        const decoded = new Uint8Array(base64UrlToBuffer(encoded));
        expect(decoded).toEqual(original);
    });
});

// ─── getCsrfToken ───────────────────────────────────────────────────────────

describe('getCsrfToken', () => {
    let metaTag;

    beforeEach(() => {
        // Clean up any existing meta tag
        document.querySelector('meta[name="csrf-token"]')?.remove();
    });

    afterEach(() => {
        metaTag?.remove();
    });

    it('returns the token from the meta tag', () => {
        metaTag = document.createElement('meta');
        metaTag.setAttribute('name', 'csrf-token');
        metaTag.setAttribute('content', 'test-token-abc123');
        document.head.appendChild(metaTag);

        expect(getCsrfToken()).toBe('test-token-abc123');
    });

    it('returns empty string when meta tag is absent', () => {
        expect(getCsrfToken()).toBe('');
    });

    it('returns empty string when content attribute is missing', () => {
        metaTag = document.createElement('meta');
        metaTag.setAttribute('name', 'csrf-token');
        document.head.appendChild(metaTag);

        expect(getCsrfToken()).toBe('');
    });

    it('returns the updated token after DOM change', () => {
        metaTag = document.createElement('meta');
        metaTag.setAttribute('name', 'csrf-token');
        metaTag.setAttribute('content', 'first-token');
        document.head.appendChild(metaTag);

        expect(getCsrfToken()).toBe('first-token');

        metaTag.setAttribute('content', 'second-token');
        expect(getCsrfToken()).toBe('second-token');
    });
});
