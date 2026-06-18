import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PortErrorsCard from '@/Components/Admin/PortErrorsCard.vue';

describe('PortErrorsCard', () => {
    it('renders the title', () => {
        const wrapper = mount(PortErrorsCard);

        expect(wrapper.text()).toContain('Port Errors');
    });

    it('shows the placeholder message when no errors prop is provided', () => {
        const wrapper = mount(PortErrorsCard);

        expect(wrapper.find('[data-testid="port-errors-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No error data available');
        expect(wrapper.text()).toContain('Prometheus integration');
    });

    it('renders the errors grid when errors prop is provided', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 5, input: 0, output: 3, collisions: 0 },
            },
        });

        expect(wrapper.find('[data-testid="port-errors-grid"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="port-errors-empty"]').exists()).toBe(false);
    });

    it('displays all four error stat categories', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 1, input: 2, output: 3, collisions: 4 },
            },
        });

        const labels = wrapper.findAll('[data-testid="port-error-label"]');
        expect(labels).toHaveLength(4);
        expect(labels[0].text()).toBe('CRC');
        expect(labels[1].text()).toBe('Input');
        expect(labels[2].text()).toBe('Output');
        expect(labels[3].text()).toBe('Collisions');
    });

    it('displays error counts', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 12, input: 0, output: 7, collisions: 0 },
            },
        });

        const counts = wrapper.findAll('[data-testid="port-error-count"]');
        expect(counts).toHaveLength(4);
        expect(counts[0].text()).toBe('12');
        expect(counts[1].text()).toBe('0');
        expect(counts[2].text()).toBe('7');
        expect(counts[3].text()).toBe('0');
    });

    it('applies muted color to zero counts', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 5, input: 0, output: 0, collisions: 0 },
            },
        });

        const counts = wrapper.findAll('[data-testid="port-error-count"]');

        expect(counts[0].classes()).toContain('text-[var(--color-text)]');
        expect(counts[1].classes()).toContain('text-[var(--color-text-muted)]');
        expect(counts[2].classes()).toContain('text-[var(--color-text-muted)]');
        expect(counts[3].classes()).toContain('text-[var(--color-text-muted)]');
    });

    it('applies normal text color to non-zero counts', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 1, input: 2, output: 3, collisions: 4 },
            },
        });

        const counts = wrapper.findAll('[data-testid="port-error-count"]');
        counts.forEach((count) => {
            expect(count.classes()).toContain('text-[var(--color-text)]');
        });
    });

    it('shows clean status message when all errors are zero', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 0, input: 0, output: 0, collisions: 0 },
            },
        });

        const clean = wrapper.find('[data-testid="port-errors-clean"]');
        expect(clean.exists()).toBe(true);
        expect(clean.text()).toBe('No errors detected');
        expect(clean.classes()).toContain('text-[var(--color-success)]');
    });

    it('does not show clean status message when errors exist', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 1, input: 0, output: 0, collisions: 0 },
            },
        });

        expect(wrapper.find('[data-testid="port-errors-clean"]').exists()).toBe(false);
    });

    it('defaults missing error keys to zero', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 3 },
            },
        });

        const counts = wrapper.findAll('[data-testid="port-error-count"]');
        expect(counts[0].text()).toBe('3');
        expect(counts[1].text()).toBe('0');
        expect(counts[2].text()).toBe('0');
        expect(counts[3].text()).toBe('0');
    });

    it('applies border-left to even-indexed stats', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 0, input: 0, output: 0, collisions: 0 },
            },
        });

        const stats = wrapper.findAll('[data-testid^="port-error-stat-"]');

        expect(stats[0].classes()).toContain('pr-4');
        expect(stats[0].classes()).not.toContain('border-l');

        expect(stats[1].classes()).toContain('border-l');
        expect(stats[1].classes()).toContain('pl-4');

        expect(stats[2].classes()).toContain('pr-4');
        expect(stats[2].classes()).not.toContain('border-l');

        expect(stats[3].classes()).toContain('border-l');
        expect(stats[3].classes()).toContain('pl-4');
    });

    it('applies bottom border to first two stats', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 0, input: 0, output: 0, collisions: 0 },
            },
        });

        const stats = wrapper.findAll('[data-testid^="port-error-stat-"]');

        expect(stats[0].classes()).toContain('border-b');
        expect(stats[1].classes()).toContain('border-b');
        expect(stats[2].classes()).not.toContain('border-b');
        expect(stats[3].classes()).not.toContain('border-b');
    });

    it('applies font-variation-settings to error counts', () => {
        const wrapper = mount(PortErrorsCard, {
            props: {
                errors: { crc: 1, input: 0, output: 0, collisions: 0 },
            },
        });

        const count = wrapper.find('[data-testid="port-error-count"]');
        expect(count.attributes('style')).toContain("font-variation-settings: 'opsz' 28");
    });
});
