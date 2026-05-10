import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Ipv6Detection from '@/Pages/Admin/Settings/Ipv6Detection.vue';

const mockPut = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ url: '/admin/settings/ipv6-detection' }),
    useForm: (data) => ({
        ...data,
        errors: {},
        processing: false,
        put: mockPut,
    }),
}));

globalThis.route = (name) => {
    const base = 'https://aperture.local.js42.io';
    if (name === 'admin.settings.integrations') return `${base}/admin/settings/integrations`;
    if (name === 'admin.settings.ipv6-detection.update') return `${base}/admin/settings/ipv6-detection`;
    return `${base}/admin/settings/${name.replace('admin.settings.', '')}`;
};

describe('Ipv6Detection.vue', () => {
    function mountComponent(settings = {}) {
        return mount(Ipv6Detection, {
            props: {
                settings: {
                    detection_endpoint: '',
                    jwks_url: '',
                    ...settings,
                },
            },
        });
    }

    beforeEach(() => {
        mockPut.mockReset();
    });

    it('renders page title', () => {
        const wrapper = mountComponent();
        expect(wrapper.get('[data-testid="page-title"]').text()).toBe('IPv6 Detection');
    });

    it('renders form with all fields', () => {
        const wrapper = mountComponent();

        expect(wrapper.find('[data-testid="detection-enabled-toggle"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="detection-endpoint-input"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="jwks-url-input"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="jwt-audience-input"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="jwt-issuer-input"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="action-save"]').exists()).toBe(true);
    });

    it('populates form with existing settings', () => {
        const wrapper = mountComponent({
            detection_endpoint: 'https://{uuid}.ipv6.test.com',
            jwks_url: 'https://ipv6.test.com/.well-known/jwks.json',
            jwt_audience: 'aperture',
            jwt_issuer: 'borealis',
        });

        const endpointInput = wrapper.get('[data-testid="detection-endpoint-input"]');
        const jwksInput = wrapper.get('[data-testid="jwks-url-input"]');
        const audInput = wrapper.get('[data-testid="jwt-audience-input"]');
        const issInput = wrapper.get('[data-testid="jwt-issuer-input"]');

        expect(endpointInput.element.value).toBe('https://{uuid}.ipv6.test.com');
        expect(jwksInput.element.value).toBe('https://ipv6.test.com/.well-known/jwks.json');
        expect(audInput.element.value).toBe('aperture');
        expect(issInput.element.value).toBe('borealis');
    });

    it('submits form via PUT', async () => {
        const wrapper = mountComponent();

        await wrapper.get('[data-testid="ipv6-settings-form"]').trigger('submit');

        expect(mockPut).toHaveBeenCalledWith('https://aperture.local.js42.io/admin/settings/ipv6-detection');
    });
});
