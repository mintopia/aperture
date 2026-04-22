import { ACCENT_PRESETS, applyAccentColor } from './useAccentColor.js';

export { ACCENT_PRESETS };
export { useAccentColor as useAccentHue } from './useAccentColor.js';

export function applyAccentHue(hue, mode = 'dark') {
    const preset = ACCENT_PRESETS.find((p) => p.hue === hue);
    const l = preset ? preset.l : 72;
    const c = preset ? preset.c : 0.19;
    applyAccentColor(hue, c, l, mode);
}
