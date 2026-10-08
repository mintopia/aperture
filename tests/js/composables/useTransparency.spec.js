import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { resetTransparencyState, useTransparency } from '@/composables/useTransparency';

const html = () => document.documentElement;

function stubMatchMedia(matches) {
    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => ({ matches })),
    );
}

describe('useTransparency', () => {
    beforeEach(() => {
        localStorage.clear();
        html().removeAttribute('data-transparency');
        stubMatchMedia(false);
        resetTransparencyState();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('is reduced and sets the attribute when stored value is 1', () => {
        localStorage.setItem('reduceTransparency', '1');
        const { reduced } = useTransparency();
        expect(reduced.value).toBe(true);
        expect(html().getAttribute('data-transparency')).toBe('reduced');
    });

    it('is not reduced when stored value is 0 even if the OS prefers reduced transparency', () => {
        stubMatchMedia(true);
        localStorage.setItem('reduceTransparency', '0');
        const { reduced } = useTransparency();
        expect(reduced.value).toBe(false);
        expect(html().hasAttribute('data-transparency')).toBe(false);
    });

    it('follows matchMedia when nothing is stored', () => {
        stubMatchMedia(true);
        const { reduced } = useTransparency();
        expect(reduced.value).toBe(true);
        expect(html().getAttribute('data-transparency')).toBe('reduced');
    });

    it('is not reduced when nothing is stored and the OS has no preference', () => {
        const { reduced } = useTransparency();
        expect(reduced.value).toBe(false);
        expect(html().hasAttribute('data-transparency')).toBe(false);
    });

    it('toggle flips, persists and updates the attribute', () => {
        const { reduced, toggleTransparency } = useTransparency();

        toggleTransparency();
        expect(reduced.value).toBe(true);
        expect(localStorage.getItem('reduceTransparency')).toBe('1');
        expect(html().getAttribute('data-transparency')).toBe('reduced');

        toggleTransparency();
        expect(reduced.value).toBe(false);
        expect(localStorage.getItem('reduceTransparency')).toBe('0');
        expect(html().hasAttribute('data-transparency')).toBe(false);
    });

    it('shares state between callers', () => {
        const a = useTransparency();
        const b = useTransparency();
        a.toggleTransparency();
        expect(b.reduced.value).toBe(true);
    });

    it('does not break when localStorage throws', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        stubMatchMedia(true);

        const { reduced, toggleTransparency } = useTransparency();
        expect(reduced.value).toBe(true);

        expect(() => toggleTransparency()).not.toThrow();
        expect(reduced.value).toBe(false);
        expect(html().hasAttribute('data-transparency')).toBe(false);
    });

    it('falls back to not reduced when matchMedia is unavailable', () => {
        vi.stubGlobal('matchMedia', undefined);
        const { reduced } = useTransparency();
        expect(reduced.value).toBe(false);
    });
});
