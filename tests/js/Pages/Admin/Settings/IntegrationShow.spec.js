import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IntegrationShow from '@/Pages/Admin/Settings/IntegrationShow.vue';

const putMock = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
    router: {
        reload: vi.fn(),
    },
    useForm: vi.fn((data) => ({
        ...data,
        put: putMock,
        errors: {},
        processing: false,
    })),
}));

describe('IntegrationShow.vue', () => {
    const defaultService = {
        id: 'opnsense',
        name: 'OPNsense',
        description: 'Firewall integration',
        health: true,
        config: {
            endpoint: 'https://opnsense.example.com',
            key: 'test-key',
            verify_ssl: '1',
        },
        fields: [
            {
                key: 'endpoint',
                type: 'url',
                label: 'API Endpoint',
                placeholder: 'https://opnsense.local/api',
                help: 'Base URL',
                required: false,
            },
            {
                key: 'key',
                type: 'password',
                label: 'API Key',
                placeholder: '',
                help: 'API key',
                required: false,
            },
            {
                key: 'verify_ssl',
                type: 'toggle',
                label: 'Verify SSL',
                placeholder: '',
                help: 'Verify SSL cert',
                required: false,
            },
        ],
        capabilities: [
            { name: 'dhcp', active: true },
            { name: 'firewall', active: false },
        ],
        logs: [
            {
                id: 1,
                success: true,
                message: 'Connected successfully',
                tested_at: '2026-04-16T08:23:30+00:00',
            },
            {
                id: 2,
                success: false,
                message: 'Request failed',
                tested_at: '2026-04-16T08:20:00+00:00',
            },
        ],
    };

    function mountPage(props = {}) {
        return mount(IntegrationShow, {
            props: {
                service: {
                    ...defaultService,
                    ...props.service,
                },
            },
            global: {
                mocks: {
                    route: global.route,
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    SettingsNav: { template: '<div><slot /></div>' },
                    StatusPill: {
                        template: '<span>{{ label }}</span>',
                        props: ['status', 'label'],
                    },
                    CapabilityTag: {
                        template: '<span>{{ name }}</span>',
                        props: ['name', 'active'],
                    },
                    FormField: {
                        template: '<div><label>{{ label }}</label><slot /></div>',
                        props: ['label', 'name', 'error'],
                    },
                },
            },
        });
    }

    beforeEach(() => {
        putMock.mockClear();
        global.route = vi.fn((name, param) => {
            if (param) {
                return `/${name}/${param}`;
            }

            return `/${name}`;
        });
        global.fetch = vi.fn();
    });

    it('renders page title with service name', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('OPNsense');
    });

    it('renders back link', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="back-link"]').text()).toContain('Back to Services');
    });

    it('shows health status', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="health-status"]').text()).toContain('Healthy');
    });

    it('renders config form fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="config-form"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="field-input-endpoint"]').element.value).toBe('https://opnsense.example.com');
        expect(wrapper.find('[data-testid="field-input-key"]').element.value).toBe('test-key');
    });

    it('renders url input for url-type fields', () => {
        const wrapper = mountPage();
        const input = wrapper.find('[data-testid="field-input-endpoint"]');

        expect(input.exists()).toBe(true);
        expect(input.attributes('type')).toBe('url');
    });

    it('renders password input for password-type fields', () => {
        const wrapper = mountPage();
        const input = wrapper.find('[data-testid="field-input-key"]');

        expect(input.exists()).toBe(true);
        expect(input.attributes('type')).toBe('password');
    });

    it('renders toggle button for toggle-type fields', () => {
        const wrapper = mountPage();
        const toggle = wrapper.find('[data-testid="field-toggle-verify_ssl"]');

        expect(toggle.exists()).toBe(true);
        expect(toggle.attributes('role')).toBe('switch');
        expect(toggle.attributes('aria-checked')).toBe('true');
    });

    it('displays field help text', () => {
        const wrapper = mountPage();
        const helpTexts = wrapper.findAll('.text-xs.text-\\[var\\(--color-text-muted\\)\\]');
        const helpContents = helpTexts.map((el) => el.text());

        expect(helpContents).toContain('Base URL');
        expect(helpContents).toContain('API key');
        expect(helpContents).toContain('Verify SSL cert');
    });

    it('renders capability tags', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="capability-dhcp"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="capability-firewall"]').exists()).toBe(true);
    });

    it('renders health log entries', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="health-log-table"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="health-log-row-0"]').text()).toContain('Connected successfully');
        expect(wrapper.find('[data-testid="health-log-row-1"]').text()).toContain('Request failed');
    });

    it('shows empty state when no logs', () => {
        const wrapper = mountPage({
            service: {
                logs: [],
            },
        });

        expect(wrapper.find('[data-testid="health-log-table"]').text()).toContain('No connection tests recorded.');
    });
});
