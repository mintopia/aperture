import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import EmptyState from '@/Components/UI/EmptyState.vue';

describe('EmptyState', () => {
    it('renders with required props', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No items found' },
        });
        expect(wrapper.text()).toContain('No items found');
    });

    it('has data-testid attribute', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No items found' },
        });
        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
    });

    it('renders description when provided', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No items', description: 'Try adding some items.' },
        });
        expect(wrapper.text()).toContain('Try adding some items.');
    });

    it('does not render description paragraph when not provided', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No items' },
        });
        const paragraphs = wrapper.findAll('p');
        expect(paragraphs).toHaveLength(1); // only the title
    });

    it('renders icon slot content', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No items' },
            slots: { icon: '<svg data-testid="custom-icon"></svg>' },
        });
        expect(wrapper.find('[data-testid="custom-icon"]').exists()).toBe(true);
    });

    it('renders default slot content', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No items' },
            slots: { default: '<button>Add Item</button>' },
        });
        expect(wrapper.find('button').exists()).toBe(true);
        expect(wrapper.find('button').text()).toBe('Add Item');
    });

    it('renders both icon and default slots together', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'Empty' },
            slots: {
                icon: '<svg class="icon"></svg>',
                default: '<button>Create</button>',
            },
        });
        expect(wrapper.find('.icon').exists()).toBe(true);
        expect(wrapper.find('button').exists()).toBe(true);
    });

    it('has no card container styling (no border, no bg, no rounded)', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'Empty' },
        });

        const emptyState = wrapper.find('[data-testid="empty-state"]');
        expect(emptyState.classes()).not.toContain('border-dashed');
        expect(emptyState.classes()).not.toContain('rounded-xl');
    });

    it('uses font-heading and text-secondary for the title', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'Empty' },
        });

        const title = wrapper.findAll('p')[0];
        expect(title.classes()).toContain('font-heading');
        expect(title.classes()).toContain('text-[var(--color-text-secondary)]');
    });
});
