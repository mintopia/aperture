import { describe, it, expect, beforeEach } from 'vitest';
import {
    DEFAULT_INTENSITY,
    MAX_SCALE,
    MIN_SCALE,
    clampIntensity,
    intensityToScale,
    resetBackgroundIntensityState,
    useBackgroundIntensity,
} from '@/composables/useBackgroundIntensity';

const html = () => document.documentElement;
const scaleOnRoot = () => Number(html().style.getPropertyValue('--bg-intensity'));

describe('useBackgroundIntensity', () => {
    beforeEach(() => {
        localStorage.clear();
        html().style.removeProperty('--bg-intensity');
        resetBackgroundIntensityState();
    });

    it('defaults to the mid-range, which is a chroma scale of exactly 1', () => {
        const { intensity } = useBackgroundIntensity();
        expect(intensity.value).toBe(DEFAULT_INTENSITY);
        expect(scaleOnRoot()).toBe(1);
        expect(intensityToScale(DEFAULT_INTENSITY)).toBe(1);
    });

    it('maps the slider ends to the muted and vivid scales', () => {
        expect(intensityToScale(0)).toBe(MIN_SCALE);
        expect(intensityToScale(100)).toBe(MAX_SCALE);
    });

    it('applies a stored value on first use', () => {
        localStorage.setItem('backgroundIntensity', '100');
        const { intensity } = useBackgroundIntensity();
        expect(intensity.value).toBe(100);
        expect(scaleOnRoot()).toBe(MAX_SCALE);
    });

    it('updates the CSS custom property live and persists the value', () => {
        const { intensity, setIntensity } = useBackgroundIntensity();
        setIntensity('0');
        expect(intensity.value).toBe(0);
        expect(scaleOnRoot()).toBe(MIN_SCALE);
        expect(localStorage.getItem('backgroundIntensity')).toBe('0');
    });

    it('clamps and sanitises values', () => {
        expect(clampIntensity(250)).toBe(100);
        expect(clampIntensity(-4)).toBe(0);
        expect(clampIntensity(33.6)).toBe(34);
        expect(clampIntensity('nope')).toBe(DEFAULT_INTENSITY);
        localStorage.setItem('backgroundIntensity', 'garbage');
        expect(useBackgroundIntensity().intensity.value).toBe(DEFAULT_INTENSITY);
    });

    it('shares one source of truth between callers', () => {
        const first = useBackgroundIntensity();
        const second = useBackgroundIntensity();
        first.setIntensity(80);
        expect(second.intensity.value).toBe(80);
    });

    it('still applies the value when storage is unavailable', () => {
        const original = Storage.prototype.setItem;
        Storage.prototype.setItem = () => {
            throw new Error('blocked');
        };
        try {
            const { intensity, setIntensity } = useBackgroundIntensity();
            setIntensity(70);
            expect(intensity.value).toBe(70);
            expect(scaleOnRoot()).toBeCloseTo(intensityToScale(70));
        } finally {
            Storage.prototype.setItem = original;
        }
    });
});
