<script setup>
import { computed } from 'vue';
import { useTransparency } from '@/composables/useTransparency';
import { useGlassSheenPreference } from '@/composables/useGlassSheenPreference';
import { useAnimatedBackground } from '@/composables/useAnimatedBackground';
import {
    useBackgroundIntensity,
    DEFAULT_INTENSITY,
    MAX_INTENSITY,
    MIN_INTENSITY,
} from '@/composables/useBackgroundIntensity';

const { reduced, toggleTransparency } = useTransparency();
const { sheenEnabled, setSheen } = useGlassSheenPreference();
const { animated, setAnimated } = useAnimatedBackground();
const { intensity, setIntensity } = useBackgroundIntensity();

const intensityLabel = computed(() => {
    if (intensity.value <= 20) return 'Muted';
    if (intensity.value >= 80) return 'Vivid';
    return intensity.value === DEFAULT_INTENSITY ? 'Default' : 'Balanced';
});

const toggles = computed(() => [
    {
        id: 'reduce-transparency',
        label: 'Reduce transparency',
        hint: 'Solid surfaces instead of frosted glass, and no background wash. The colour slider still tints the page.',
        checked: reduced.value,
        change: toggleTransparency,
    },
    {
        id: 'glass-sheen',
        label: 'Glass sheen',
        hint: 'A soft highlight on glass panels that follows your pointer.',
        checked: sheenEnabled.value,
        change: () => setSheen(!sheenEnabled.value),
    },
    {
        id: 'animated-background',
        label: 'Animated background',
        hint: 'Slowly drifting colour on the portal and login pages. Off by default if your system prefers reduced motion.',
        checked: animated.value,
        change: () => setAnimated(!animated.value),
    },
]);
</script>

<template>
    <section id="appearance" data-testid="appearance-section" aria-labelledby="appearance-heading">
        <h2
            id="appearance-heading"
            class="font-heading mb-1 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
        >
            Appearance
        </h2>
        <p class="mb-2 text-[13px] text-[var(--color-text-secondary)]">
            These preferences are saved in this browser only.
        </p>

        <div class="divide-y divide-[var(--color-border)]">
            <div class="py-4">
                <div class="flex items-baseline justify-between gap-4">
                    <label
                        for="appearance-background-intensity"
                        class="text-[14px] font-medium text-[var(--color-text)]"
                    >
                        Background colour
                    </label>
                    <span
                        data-testid="appearance-background-intensity-label"
                        class="font-mono text-[12px] text-[var(--color-text-secondary)]"
                    >
                        {{ intensityLabel }}
                    </span>
                </div>
                <p
                    id="appearance-background-intensity-hint"
                    class="mt-0.5 text-[13px] text-[var(--color-text-secondary)]"
                >
                    How strongly the accent colour washes the page behind the glass.
                </p>
                <div class="mt-3 flex items-center gap-3">
                    <span class="text-[12px] text-[var(--color-text-secondary)]" aria-hidden="true">Muted</span>
                    <input
                        id="appearance-background-intensity"
                        type="range"
                        data-testid="appearance-background-intensity"
                        :min="MIN_INTENSITY"
                        :max="MAX_INTENSITY"
                        step="1"
                        :value="intensity"
                        :aria-valuetext="`${intensityLabel}, ${intensity} of ${MAX_INTENSITY}`"
                        aria-describedby="appearance-background-intensity-hint"
                        class="h-2 flex-1 cursor-pointer accent-[var(--color-primary)]"
                        @input="setIntensity($event.target.value)"
                    />
                    <span class="text-[12px] text-[var(--color-text-secondary)]" aria-hidden="true">Vivid</span>
                </div>
            </div>

            <div v-for="toggle in toggles" :key="toggle.id" class="flex items-center justify-between gap-4 py-4">
                <div>
                    <label :for="`appearance-${toggle.id}`" class="text-[14px] font-medium text-[var(--color-text)]">
                        {{ toggle.label }}
                    </label>
                    <p
                        :id="`appearance-${toggle.id}-hint`"
                        class="mt-0.5 text-[13px] text-[var(--color-text-secondary)]"
                    >
                        {{ toggle.hint }}
                    </p>
                </div>
                <button
                    :id="`appearance-${toggle.id}`"
                    type="button"
                    role="switch"
                    :data-testid="`appearance-${toggle.id}`"
                    :aria-checked="toggle.checked"
                    :aria-describedby="`appearance-${toggle.id}-hint`"
                    class="relative h-[22px] w-[38px] shrink-0 rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-primary)]"
                    :class="toggle.checked ? 'bg-[var(--color-primary)]' : 'bg-[var(--color-border-hover)]'"
                    @click="toggle.change"
                >
                    <span
                        aria-hidden="true"
                        class="absolute top-[3px] left-[3px] h-4 w-4 rounded-full bg-white shadow-sm transition-transform"
                        :class="{ 'translate-x-4': toggle.checked }"
                    />
                </button>
            </div>
        </div>
    </section>
</template>
