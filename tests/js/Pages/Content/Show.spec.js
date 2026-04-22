import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Show from '@/Pages/Content/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: vi.fn(() => ({
        props: { auth: { user: null } },
    })),
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

vi.mock('marked', () => ({
    marked: {
        parse: vi.fn((content) => `<p>${content}</p>`),
    },
}));

describe('Public Page View', () => {
    function mountShow(page = { title: 'Terms', slug: 'terms', content: '# Terms' }) {
        return mount(Show, {
            props: { page },
            global: {
                stubs: {
                    PortalLayout: { template: '<div><slot /></div>' },
                },
            },
        });
    }

    it('renders page title', () => {
        const wrapper = mountShow({ title: 'Terms', slug: 'terms', content: '# Terms' });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Terms');
    });

    it('renders markdown content', () => {
        const wrapper = mountShow({ title: 'Terms', slug: 'terms', content: '**Bold text**' });
        expect(wrapper.find('[data-testid="page-content"]').exists()).toBe(true);
    });

    it('uses prose class for content', () => {
        const wrapper = mountShow({ title: 'Terms', slug: 'terms', content: 'Hello' });
        expect(wrapper.find('.prose').exists()).toBe(true);
    });
});
