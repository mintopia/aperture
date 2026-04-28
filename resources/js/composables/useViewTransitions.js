import { router } from '@inertiajs/vue3';

export function useViewTransitions() {
    if (typeof document === 'undefined') return;
    if (!router?.on) return;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

    router.on('start', (event) => {
        if (event.detail.visit.method !== 'get') return;
        const main = document.getElementById('main-content');
        if (main) main.classList.add('page-exit');
    });

    router.on('navigate', () => {
        const main = document.getElementById('main-content');
        if (!main) return;
        main.classList.remove('page-exit');
        main.classList.add('page-enter');
        main.addEventListener('animationend', () => main.classList.remove('page-enter'), { once: true });
    });
}
