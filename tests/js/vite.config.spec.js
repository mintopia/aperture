import { describe, expect, it } from 'vitest';
import { resolveHmrHost } from '../../vite.config.js';

describe('resolveHmrHost', () => {
    it('returns host from APP_URL instead of 0.0.0.0 binding host', () => {
        expect(resolveHmrHost('https://aperture.local.js42.io')).toBe('aperture.local.js42.io');
    });

    it('falls back to localhost when APP_URL is invalid', () => {
        expect(resolveHmrHost('not-a-url')).toBe('localhost');
    });
});
