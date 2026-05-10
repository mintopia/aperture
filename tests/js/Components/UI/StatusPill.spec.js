import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import StatusPill from '@/Components/UI/StatusPill.vue';

describe('StatusPill', () => {
    it('renders with required props', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'success', label: 'Active' },
        });
        expect(wrapper.text()).toContain('Active');
    });

    it('has data-testid attribute', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'success', label: 'Active' },
        });
        expect(wrapper.find('[data-testid="status-pill"]').exists()).toBe(true);
    });

    it('renders correct symbol for each status', () => {
        const symbolMap = {
            success: '\u2713',
            danger: '\u2717',
            warning: '\u25B2',
            info: '\u2713',
            neutral: '–',
            muted: '·',
        };
        Object.entries(symbolMap).forEach(([status, symbol]) => {
            const wrapper = mount(StatusPill, {
                props: { status, label: 'Test' },
            });
            const sym = wrapper.find('[data-testid="status-symbol"]');
            expect(sym.exists()).toBe(true);
            expect(sym.text()).toBe(symbol);
        });
    });

    it('renders a symbol for neutral status (WCAG 1.4.1 — not colour alone)', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'neutral', label: 'Unknown' },
        });
        const symbol = wrapper.find('[data-testid="status-symbol"]');
        expect(symbol.exists()).toBe(true);
        expect(symbol.text().trim().length).toBeGreaterThan(0);
        expect(symbol.text()).toBe('–');
    });

    it('renders a symbol for muted status (WCAG 1.4.1 — not colour alone)', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'muted', label: 'Muted' },
        });
        const symbol = wrapper.find('[data-testid="status-symbol"]');
        expect(symbol.exists()).toBe(true);
        expect(symbol.text().trim().length).toBeGreaterThan(0);
        expect(symbol.text()).toBe('·');
    });

    it('renders label text for each status', () => {
        const cases = [
            { status: 'success', label: 'Active' },
            { status: 'danger', label: 'Failed' },
            { status: 'warning', label: 'Pending' },
            { status: 'info', label: 'Info' },
            { status: 'neutral', label: 'Unknown' },
            { status: 'muted', label: 'Muted' },
        ];
        cases.forEach(({ status, label }) => {
            const wrapper = mount(StatusPill, {
                props: { status, label },
            });
            expect(wrapper.text()).toContain(label);
        });
    });

    it('applies correct CSS classes for each status', () => {
        const statuses = ['success', 'danger', 'warning', 'info', 'neutral', 'muted'];
        statuses.forEach((status) => {
            const wrapper = mount(StatusPill, {
                props: { status, label: 'Test' },
            });
            const pill = wrapper.find('[data-testid="status-pill"]');
            expect(pill.classes()).toContain('inline-flex');
            expect(pill.classes()).toContain('rounded');
            expect(pill.classes()).toContain('text-[11px]');
            expect(pill.classes()).toContain('font-semibold');
        });
    });

    it('uses semantic CSS variables for status colors', () => {
        const colorMap = {
            success: { cssVar: '--color-success', opacity: '14' },
            danger: { cssVar: '--color-danger', opacity: '14' },
            warning: { cssVar: '--color-warning', opacity: '14' },
            info: { cssVar: '--color-info', opacity: '14' },
            neutral: { cssVar: '--color-text-muted', opacity: '14' },
            muted: { cssVar: '--color-text-muted', opacity: '10' },
        };
        Object.entries(colorMap).forEach(([status, { cssVar, opacity }]) => {
            const wrapper = mount(StatusPill, {
                props: { status, label: 'Test' },
            });
            const pill = wrapper.find('[data-testid="status-pill"]');
            const classes = pill.classes().join(' ');
            expect(classes).toContain(`bg-[var(${cssVar})]/${opacity}`);
            expect(classes).toContain(`text-[var(${cssVar})]`);
            expect(classes).toContain(`border-[var(${cssVar})]/${opacity}`);
        });
    });

    it('hides symbol from assistive technology with aria-hidden', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'success', label: 'Active' },
        });
        const sym = wrapper.find('[data-testid="status-symbol"]');
        expect(sym.attributes('aria-hidden')).toBe('true');
    });

    it('neutral symbol has aria-hidden="true"', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'neutral', label: 'Active' },
        });
        const sym = wrapper.find('[data-testid="status-symbol"]');
        expect(sym.attributes('aria-hidden')).toBe('true');
    });

    it('muted symbol has aria-hidden="true"', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'muted', label: 'Inactive' },
        });
        const sym = wrapper.find('[data-testid="status-symbol"]');
        expect(sym.attributes('aria-hidden')).toBe('true');
    });

    it('updates symbol when status prop changes', async () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'success', label: 'Active' },
        });

        let sym = wrapper.find('[data-testid="status-symbol"]');
        expect(sym.text()).toBe('\u2713');

        await wrapper.setProps({ status: 'danger' });
        sym = wrapper.find('[data-testid="status-symbol"]');
        expect(sym.text()).toBe('\u2717');
    });
});
