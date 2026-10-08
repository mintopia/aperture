import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { resetAnimatedBackgroundState, useAnimatedBackground } from '@/composables/useAnimatedBackground';

const html = () => document.documentElement;

function stubReducedMotion(matches) {
    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => ({ matches })),
    );
}

describe('useAnimatedBackground', () => {
    beforeEach(() => {
        localStorage.clear();
        html().removeAttribute('data-animated-bg');
        stubReducedMotion(false);
        resetAnimatedBackgroundState();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('animates by default', () => {
        expect(useAnimatedBackground().animated.value).toBe(true);
        expect(html().hasAttribute('data-animated-bg')).toBe(false);
    });

    it('defaults to still when the OS prefers reduced motion', () => {
        stubReducedMotion(true);
        expect(useAnimatedBackground().animated.value).toBe(false);
        expect(html().getAttribute('data-animated-bg')).toBe('off');
    });

    it('lets an explicit stored choice override the OS preference', () => {
        stubReducedMotion(true);
        localStorage.setItem('animatedBackground', '1');
        expect(useAnimatedBackground().animated.value).toBe(true);
        expect(html().hasAttribute('data-animated-bg')).toBe(false);
    });

    it('honours a stored off choice', () => {
        localStorage.setItem('animatedBackground', '0');
        expect(useAnimatedBackground().animated.value).toBe(false);
        expect(html().getAttribute('data-animated-bg')).toBe('off');
    });

    it('persists changes and toggles the root attribute', () => {
        const { animated, setAnimated } = useAnimatedBackground();
        setAnimated(false);
        expect(animated.value).toBe(false);
        expect(localStorage.getItem('animatedBackground')).toBe('0');
        expect(html().getAttribute('data-animated-bg')).toBe('off');

        setAnimated(true);
        expect(localStorage.getItem('animatedBackground')).toBe('1');
        expect(html().hasAttribute('data-animated-bg')).toBe(false);
    });

    it('copes with missing matchMedia', () => {
        vi.stubGlobal('matchMedia', undefined);
        expect(useAnimatedBackground().animated.value).toBe(true);
    });
});
