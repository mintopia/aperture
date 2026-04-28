import { ref, watch, onMounted, onUnmounted, toValue } from 'vue';

function easeOutExpo(t) {
    return t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
}

export function useCountUp(target, elementRef, options = {}) {
    const { duration = 800, delay = 0 } = options;

    const noIO = typeof window === 'undefined' || !('IntersectionObserver' in window);
    const reducedMotion =
        typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const skip = noIO || reducedMotion;

    const display = ref(skip ? toValue(target) : 0);
    let hasAnimated = skip;
    let frame = null;
    let observer = null;

    function animate(from, to) {
        if (frame) cancelAnimationFrame(frame);
        if (from === to || skip) {
            display.value = to;
            return;
        }
        const start = performance.now();
        function step(now) {
            const progress = Math.min((now - start) / duration, 1);
            display.value = Math.round(from + (to - from) * easeOutExpo(progress));
            if (progress < 1) frame = requestAnimationFrame(step);
        }
        frame = requestAnimationFrame(step);
    }

    watch(
        () => toValue(target),
        (newVal, oldVal) => {
            if (hasAnimated) {
                animate(oldVal ?? 0, newVal);
            }
        },
    );

    if (!skip) {
        onMounted(() => {
            observer = new IntersectionObserver(
                ([entry]) => {
                    if (entry.isIntersecting && !hasAnimated) {
                        hasAnimated = true;
                        const current = toValue(target);
                        if (delay > 0) {
                            setTimeout(() => animate(0, current), delay);
                        } else {
                            animate(0, current);
                        }
                        observer.disconnect();
                    }
                },
                { threshold: 0.1 },
            );

            const el = toValue(elementRef);
            if (el) observer.observe(el.$el ?? el);
        });
    }

    onUnmounted(() => {
        if (frame) cancelAnimationFrame(frame);
        observer?.disconnect();
    });

    return display;
}
