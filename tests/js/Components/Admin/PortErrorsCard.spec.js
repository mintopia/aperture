import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PortErrorsCard from '@/Components/Admin/PortErrorsCard.vue';

describe('PortErrorsCard', () => {
    it('renders the title', () => {
        const wrapper = mount(PortErrorsCard);

        expect(wrapper.find('[data-testid="port-errors-title"]').text()).toBe('Port Errors');
    });

    it('shows the placeholder message', () => {
        const wrapper = mount(PortErrorsCard);

        expect(wrapper.find('[data-testid="port-errors-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No error data available');
        expect(wrapper.text()).toContain('Prometheus integration');
    });
});
