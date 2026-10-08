import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';
import { intensityToScale } from '@/composables/useBackgroundIntensity';

/*
 * Resolves the real tokens in dispatch.css for a mode/intensity/hue, composites the worst-case
 * background wash and glass tint, and checks WCAG AA (4.5:1) for text on top.
 */
const css = readFileSync(resolve(__dirname, '../../../resources/css/themes/dispatch.css'), 'utf8').replace(
    /\/\*[\s\S]*?\*\//g,
    '',
);

const rules = [...css.matchAll(/([^{}]+)\{([^}]*)\}/g)].map(([, selector, body], order) => ({
    selector: selector.trim(),
    order,
    specificity: (selector.match(/\[/g) ?? []).length,
    declarations: [...body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)].map(([, name, value]) => [name, value.trim()]),
}));

function tokensFor({ mode, reduced = false, hue, scale }) {
    const active = rules
        .filter(({ selector }) => {
            if (selector.includes("data-mode='light'") && mode !== 'light') return false;
            if (selector.includes("data-transparency='reduced'") && !reduced) return false;
            return selector.startsWith("[data-theme='dispatch']");
        })
        .sort((a, b) => a.specificity - b.specificity || a.order - b.order);

    const tokens = {};
    for (const rule of active) for (const [name, value] of rule.declarations) tokens[name] = value;
    tokens['--accent-hue'] = String(hue);
    tokens['--bg-intensity'] = String(scale);

    return tokens;
}

function resolveValue(value, tokens) {
    let out = value;
    while (out.includes('var(')) {
        out = out.replace(/var\((--[\w-]+)\)/g, (_, name) => {
            if (!(name in tokens)) throw new Error(`Unknown token ${name}`);
            return tokens[name];
        });
    }
    // Evaluate calc() groups (inner-most first) with plain arithmetic.
    while (out.includes('calc(')) {
        out = out.replace(/calc\(([^()]*)\)/g, (_, expr) => {
            if (!/^[\d\s.+\-*/]+$/.test(expr)) throw new Error(`Unsupported calc ${expr}`);
            return String(Function(`return (${expr})`)());
        });
    }

    return out;
}

function oklchToSrgb(l, c, h) {
    const hr = (h * Math.PI) / 180;
    const a = c * Math.cos(hr);
    const b = c * Math.sin(hr);
    const l_ = (l + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m_ = (l - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s_ = (l - 0.0894841775 * a - 1.291485548 * b) ** 3;
    const lin = [
        4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_,
        -1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_,
        -0.0041960863 * l_ - 0.7034186147 * m_ + 1.707614701 * s_,
    ];
    const [r, g, bl] = lin.map((v) => {
        const clamped = Math.min(1, Math.max(0, v));
        return clamped <= 0.0031308 ? 12.92 * clamped : 1.055 * clamped ** (1 / 2.4) - 0.055;
    });

    return { r, g, b: bl };
}

function parseColor(value, tokens) {
    const resolved = resolveValue(value, tokens);
    const match = resolved.match(/oklch\(\s*([\d.]+)%\s+([\d.]+)\s+(-?[\d.]+)\s*(?:\/\s*([\d.]+))?\)/);
    if (!match) throw new Error(`Not an oklch colour: ${resolved}`);
    const [, l, c, h, a] = match;

    return { ...oklchToSrgb(Number(l) / 100, Number(c), Number(h)), a: a === undefined ? 1 : Number(a) };
}

function over(top, bottom) {
    const mix = (t, b) => t * top.a + b * (1 - top.a);

    return { r: mix(top.r, bottom.r), g: mix(top.g, bottom.g), b: mix(top.b, bottom.b), a: 1 };
}

function luminance({ r, g, b }) {
    const lin = (v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);

    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

function contrast(a, b) {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
}

const SCALES = { muted: intensityToScale(0), default: intensityToScale(50), vivid: intensityToScale(100) };
const HUES = [20, 55, 135, 185, 230, 295, 350];
const TEXT_TOKENS = ['--color-text', '--color-text-secondary', '--color-text-muted'];

const surfaces = [
    {
        name: 'frost',
        tints: ['--glass-frost-tint', '--glass-frost-tint-strong'],
        blobs: ['--ambient-admin-a', '--ambient-admin-b'],
    },
    {
        name: 'lens',
        tints: ['--glass-lens-tint', '--glass-lens-tint-strong'],
        blobs: ['--ambient-lens-a', '--ambient-lens-b', '--ambient-lens-c'],
    },
];

describe('background wash contrast', () => {
    for (const mode of ['dark', 'light']) {
        for (const [scaleName, scale] of Object.entries(SCALES)) {
            for (const hue of HUES) {
                it(`${mode} / ${scaleName} / hue ${hue}: text on glass meets AA`, () => {
                    const tokens = tokensFor({ mode, hue, scale });
                    const bg = parseColor(tokens['--color-bg'], tokens);

                    for (const surface of surfaces) {
                        const blobs = surface.blobs.map((name) => parseColor(tokens[name], tokens));
                        // Worst case: every blob stacked at full strength on the page colour, or any one alone.
                        const pages = [bg, blobs.reduce((below, blob) => over(blob, below), bg)];
                        for (const blob of blobs) pages.push(over(blob, bg));

                        for (const page of pages) {
                            for (const tintName of surface.tints) {
                                const glass = over(parseColor(tokens[tintName], tokens), page);
                                for (const textName of TEXT_TOKENS) {
                                    const text = parseColor(tokens[textName], tokens);
                                    expect(
                                        contrast(text, glass),
                                        `${surface.name} ${tintName} ${textName}`,
                                    ).toBeGreaterThanOrEqual(4.5);
                                }
                            }
                        }
                    }
                });
            }

            it(`${mode} / ${scaleName}: text on the solid reduced-transparency page meets AA`, () => {
                const tokens = tokensFor({ mode, reduced: true, hue: 55, scale });
                const bg = parseColor(tokens['--color-bg'], tokens);
                for (const textName of TEXT_TOKENS) {
                    expect(contrast(parseColor(tokens[textName], tokens), bg), textName).toBeGreaterThanOrEqual(4.5);
                }
            });
        }

        it(`${mode}: the default wash carries clearly more colour than the old near-neutral one`, () => {
            const tokens = tokensFor({ mode, hue: 55, scale: SCALES.default });
            const wash = over(parseColor(tokens['--ambient-lens-a'], tokens), parseColor(tokens['--color-bg'], tokens));
            const spread = Math.max(wash.r, wash.g, wash.b) - Math.min(wash.r, wash.g, wash.b);
            expect(spread).toBeGreaterThan(0.2);
        });
    }
});
