import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import ConfigBlock from '@/Components/UI/ConfigBlock.vue';

describe('ConfigBlock', () => {
    it('renders with required props', () => {
        const wrapper = mount(ConfigBlock, {
            props: { code: 'server { listen 80; }' },
        });
        expect(wrapper.text()).toContain('server { listen 80; }');
    });

    it('has data-testid attribute', () => {
        const wrapper = mount(ConfigBlock, {
            props: { code: 'test' },
        });
        expect(wrapper.find('[data-testid="config-block"]').exists()).toBe(true);
    });

    it('renders code inside a code element', () => {
        const wrapper = mount(ConfigBlock, {
            props: { code: 'APP_KEY=base64:abc' },
        });
        const codeEl = wrapper.find('code');
        expect(codeEl.exists()).toBe(true);
        expect(codeEl.text()).toBe('APP_KEY=base64:abc');
    });

    it('wraps content in a pre element', () => {
        const wrapper = mount(ConfigBlock, {
            props: { code: 'line1\nline2' },
        });
        const pre = wrapper.find('pre');
        expect(pre.exists()).toBe(true);
    });

    it('preserves whitespace in code', () => {
        const multiline = '{\n  "key": "value"\n}';
        const wrapper = mount(ConfigBlock, {
            props: { code: multiline },
        });
        expect(wrapper.find('code').text()).toBe(multiline);
    });

    it('applies monospace font class', () => {
        const wrapper = mount(ConfigBlock, {
            props: { code: 'test' },
        });
        const pre = wrapper.find('pre');
        expect(pre.classes()).toContain('font-mono');
    });
});
