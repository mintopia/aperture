import { onMounted, onUnmounted } from 'vue';

const SELECTOR = '.glass-lens, .glass-lens-strong';

export function useGlassSheen(rootRef) {
    let frame = 0;
    let pointer = null;

    function update() {
        frame = 0;
        if (!rootRef.value || !pointer) return;

        rootRef.value.querySelectorAll(SELECTOR).forEach((el) => {
            const rect = el.getBoundingClientRect();
            el.style.setProperty('--glass-x', `${pointer.x - rect.left}px`);
            el.style.setProperty('--glass-y', `${pointer.y - rect.top}px`);
        });
    }

    function onPointerMove(e) {
        if (e.pointerType !== 'mouse') return;
        pointer = { x: e.clientX, y: e.clientY };
        if (!frame) frame = requestAnimationFrame(update);
    }

    onMounted(() => {
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
        window.addEventListener('pointermove', onPointerMove, { passive: true });
    });

    onUnmounted(() => {
        window.removeEventListener('pointermove', onPointerMove);
        if (frame) cancelAnimationFrame(frame);
    });
}
