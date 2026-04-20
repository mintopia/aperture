import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import DnsWarningBlock from '@/Components/Blocks/DnsWarningBlock.vue';

describe('DnsWarningBlock', () => {
    it('shows warning when hasDnsIssue is true', () => {
        const wrapper = mount(DnsWarningBlock, {
            props: {
                hasDnsIssue: true,
                expectedDns: '10.0.0.1',
                actualDns: '8.8.8.8',
            },
        });

        expect(wrapper.text()).toContain('Custom DNS detected.');
        expect(wrapper.text()).toContain('10.0.0.1');
        expect(wrapper.text()).toContain('8.8.8.8');
    });

    it('hides when hasDnsIssue is false', () => {
        const wrapper = mount(DnsWarningBlock, {
            props: {
                hasDnsIssue: false,
                expectedDns: '10.0.0.1',
                actualDns: '10.0.0.1',
            },
        });

        expect(wrapper.text()).not.toContain('Custom DNS detected.');
    });

    it('reads expectedDns from settings prop when provided', () => {
        const wrapper = mount(DnsWarningBlock, {
            props: {
                hasDnsIssue: true,
                settings: { expectedDns: '10.0.0.1' },
                actualDns: '8.8.8.8',
            },
        });

        expect(wrapper.text()).toContain('10.0.0.1');
    });

    it('uses top-level expectedDns over settings', () => {
        const wrapper = mount(DnsWarningBlock, {
            props: {
                hasDnsIssue: true,
                expectedDns: '10.0.0.2',
                settings: { expectedDns: '10.0.0.1' },
                actualDns: '8.8.8.8',
            },
        });

        expect(wrapper.text()).toContain('10.0.0.2');
    });

    it('has no visible content when hasDnsIssue is undefined', () => {
        const wrapper = mount(DnsWarningBlock, {
            props: {},
        });

        expect(wrapper.text()).toBe('');
    });
});
