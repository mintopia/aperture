import { mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useGlassSheen } from '@/composables/useGlassSheen';

const Host = defineComponent({
    setup() {
        const root = ref(null);
        useGlassSheen(root);
        return () =>
            h('div', { ref: root, 'data-testid': 'root' }, [
                h('div', { class: 'glass-lens', 'data-testid': 'lens' }),
                h('div', { class: 'glass-lens-strong', 'data-testid': 'lens-strong' }),
                h('div', { class: 'other', 'data-testid': 'other' }),
            ]);
    },
});

function pointerMove(pointerType, x = 100, y = 50) {
    const event = new Event('pointermove');
    Object.assign(event, { pointerType, clientX: x, clientY: y });
    window.dispatchEvent(event);
}

describe('useGlassSheen', () => {
    beforeEach(() => {
        vi.stubGlobal(
            'matchMedia',
            vi.fn(() => ({ matches: false })),
        );
        vi.stubGlobal('requestAnimationFrame', (cb) => {
            cb(0);
            return 1;
        });
        vi.stubGlobal('cancelAnimationFrame', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('sets glass vars on lens elements for mouse pointermove', () => {
        const wrapper = mount(Host);
        pointerMove('mouse', 100, 50);

        for (const id of ['lens', 'lens-strong']) {
            const el = wrapper.get(`[data-testid="${id}"]`).element;
            expect(el.style.getPropertyValue('--glass-x')).toBe('100px');
            expect(el.style.getPropertyValue('--glass-y')).toBe('50px');
        }
        expect(wrapper.get('[data-testid="other"]').element.style.getPropertyValue('--glass-x')).toBe('');
        wrapper.unmount();
    });

    it('ignores touch pointers', () => {
        const wrapper = mount(Host);
        pointerMove('touch');
        expect(wrapper.get('[data-testid="lens"]').element.style.getPropertyValue('--glass-x')).toBe('');
        wrapper.unmount();
    });

    it('does not listen under prefers-reduced-motion', () => {
        vi.stubGlobal(
            'matchMedia',
            vi.fn(() => ({ matches: true })),
        );
        const add = vi.spyOn(window, 'addEventListener');
        const wrapper = mount(Host);
        expect(add.mock.calls.some(([type]) => type === 'pointermove')).toBe(false);
        pointerMove('mouse');
        expect(wrapper.get('[data-testid="lens"]').element.style.getPropertyValue('--glass-x')).toBe('');
        wrapper.unmount();
    });

    it('removes the listener on unmount', () => {
        const remove = vi.spyOn(window, 'removeEventListener');
        const wrapper = mount(Host);
        const el = wrapper.get('[data-testid="lens"]').element;
        wrapper.unmount();
        expect(remove.mock.calls.some(([type]) => type === 'pointermove')).toBe(true);
        pointerMove('mouse');
        expect(el.style.getPropertyValue('--glass-x')).toBe('');
    });

    it('cancels a pending frame on unmount', () => {
        vi.stubGlobal('requestAnimationFrame', () => 42);
        const cancel = vi.fn();
        vi.stubGlobal('cancelAnimationFrame', cancel);
        const wrapper = mount(Host);
        pointerMove('mouse');
        wrapper.unmount();
        expect(cancel).toHaveBeenCalledWith(42);
    });
});
