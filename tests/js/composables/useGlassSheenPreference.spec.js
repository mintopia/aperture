import { describe, it, expect, beforeEach } from 'vitest';
import { resetGlassSheenPreferenceState, useGlassSheenPreference } from '@/composables/useGlassSheenPreference';

const html = () => document.documentElement;

describe('useGlassSheenPreference', () => {
    beforeEach(() => {
        localStorage.clear();
        html().removeAttribute('data-sheen');
        resetGlassSheenPreferenceState();
    });

    it('is on by default and leaves the root untouched', () => {
        expect(useGlassSheenPreference().sheenEnabled.value).toBe(true);
        expect(html().hasAttribute('data-sheen')).toBe(false);
    });

    it('is off when stored as 0 and marks the root', () => {
        localStorage.setItem('glassSheen', '0');
        expect(useGlassSheenPreference().sheenEnabled.value).toBe(false);
        expect(html().getAttribute('data-sheen')).toBe('off');
    });

    it('persists changes and toggles the root attribute', () => {
        const { sheenEnabled, setSheen } = useGlassSheenPreference();
        setSheen(false);
        expect(sheenEnabled.value).toBe(false);
        expect(localStorage.getItem('glassSheen')).toBe('0');
        expect(html().getAttribute('data-sheen')).toBe('off');

        setSheen(true);
        expect(localStorage.getItem('glassSheen')).toBe('1');
        expect(html().hasAttribute('data-sheen')).toBe(false);
    });
});
