import { flushPromises, mount } from '@vue/test-utils';
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
                response_data: '{"status":"ok"}',
                tested_at: '2026-04-16T08:23:30+00:00',
            },
            {
                id: 2,
                success: false,
                message: 'Request failed',
                response_data: null,
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

    it('sends form config data when testing connection', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            json: () => Promise.resolve({ success: true, message: 'Connected successfully' }),
        });

        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-test-connection"]').trigger('click');

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [, options] = global.fetch.mock.calls[0];
        expect(options.method).toBe('POST');
        expect(options.headers['Content-Type']).toBe('application/json');
        const body = JSON.parse(options.body);
        expect(body).toHaveProperty('endpoint', 'https://opnsense.example.com');
        expect(body).toHaveProperty('key', 'test-key');
        expect(body).toHaveProperty('verify_ssl', '1');
    });

    describe('test output toggle', () => {
        it('shows toggle button when test result has output', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () =>
                    Promise.resolve({
                        success: true,
                        message: 'Connected',
                        output: { status: 'ok' },
                    }),
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-testid="test-output-toggle"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="test-output-toggle"]').text()).toBe('Show Output');
        });

        it('does not show toggle button when test result has no output', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () =>
                    Promise.resolve({
                        success: false,
                        message: 'Connection failed',
                    }),
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-testid="test-output-toggle"]').exists()).toBe(false);
        });

        it('toggles output visibility when clicking Show/Hide Output', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () =>
                    Promise.resolve({
                        success: true,
                        message: 'Connected',
                        output: { status: 'ok' },
                    }),
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            // Initially hidden
            expect(wrapper.find('[data-testid="test-output-content"]').exists()).toBe(false);

            // Click to show
            await wrapper.find('[data-testid="test-output-toggle"]').trigger('click');
            expect(wrapper.find('[data-testid="test-output-content"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="test-output-toggle"]').text()).toBe('Hide Output');

            // Click to hide
            await wrapper.find('[data-testid="test-output-toggle"]').trigger('click');
            expect(wrapper.find('[data-testid="test-output-content"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="test-output-toggle"]').text()).toBe('Show Output');
        });

        it('displays JSON output formatted', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () =>
                    Promise.resolve({
                        success: true,
                        message: 'Connected',
                        output: { status: 'ok', version: '1.0' },
                    }),
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();
            await wrapper.find('[data-testid="test-output-toggle"]').trigger('click');

            const content = wrapper.find('[data-testid="test-output-content"]').text();
            expect(content).toContain('"status": "ok"');
            expect(content).toContain('"version": "1.0"');
        });

        it('displays string output as-is', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () =>
                    Promise.resolve({
                        success: true,
                        message: 'Connected',
                        output: 'plain text response',
                    }),
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();
            await wrapper.find('[data-testid="test-output-toggle"]').trigger('click');

            const content = wrapper.find('[data-testid="test-output-content"]').text();
            expect(content).toBe('plain text response');
        });

        it('resets output toggle when running new test', async () => {
            global.fetch = vi
                .fn()
                .mockResolvedValueOnce({
                    json: () =>
                        Promise.resolve({
                            success: true,
                            message: 'Connected',
                            output: { status: 'ok' },
                        }),
                })
                .mockResolvedValueOnce({
                    json: () =>
                        Promise.resolve({
                            success: true,
                            message: 'Connected again',
                            output: { status: 'ok2' },
                        }),
                });

            const wrapper = mountPage();

            // First test — show output
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();
            await wrapper.find('[data-testid="test-output-toggle"]').trigger('click');
            expect(wrapper.find('[data-testid="test-output-content"]').exists()).toBe(true);

            // Second test — output should be hidden again
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="test-output-content"]').exists()).toBe(false);
        });
    });

    describe('health log output toggle', () => {
        it('shows toggle button for log entries with response_data', () => {
            const wrapper = mountPage();

            expect(wrapper.find('[data-testid="log-output-toggle-0"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="log-output-toggle-1"]').exists()).toBe(false);
        });

        it('toggles log output visibility when clicking Show/Hide Output', async () => {
            const wrapper = mountPage();

            // Initially hidden
            expect(wrapper.find('[data-testid="log-output-content-0"]').exists()).toBe(false);

            // Click to show
            await wrapper.find('[data-testid="log-output-toggle-0"]').trigger('click');
            expect(wrapper.find('[data-testid="log-output-content-0"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="log-output-toggle-0"]').text()).toBe('Hide Output');

            // Click to hide
            await wrapper.find('[data-testid="log-output-toggle-0"]').trigger('click');
            expect(wrapper.find('[data-testid="log-output-content-0"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="log-output-toggle-0"]').text()).toBe('Show Output');
        });

        it('formats JSON response_data in log output', async () => {
            const wrapper = mountPage();

            await wrapper.find('[data-testid="log-output-toggle-0"]').trigger('click');

            const content = wrapper.find('[data-testid="log-output-content-0"]').text();
            expect(content).toContain('"status": "ok"');
        });
    });

    describe('select-remote fields', () => {
        const remoteService = {
            id: 'pihole',
            name: 'Pi-hole',
            description: 'DNS filtering',
            health: true,
            config: {
                endpoint: 'https://pihole.test',
                password: 'secret',
                noblock_group_id: '1',
                verify_ssl: '1',
                enabled: '1',
            },
            fields: [
                {
                    key: 'endpoint',
                    type: 'url',
                    label: 'API Endpoint',
                    placeholder: 'https://pihole.local',
                    help: 'Base URL',
                    required: false,
                },
                {
                    key: 'password',
                    type: 'password',
                    label: 'API Password',
                    placeholder: '',
                    help: 'Password',
                    required: false,
                },
                {
                    key: 'noblock_group_id',
                    type: 'select-remote',
                    label: 'Blocking Group',
                    placeholder: 'Select a group…',
                    help: 'Pi-hole group ID for clients that should have ad blocking enabled.',
                    required: false,
                    remote_url: '/admin/settings/integrations/pihole/groups',
                    remote_label: 'name',
                    remote_value: 'id',
                },
                {
                    key: 'verify_ssl',
                    type: 'toggle',
                    label: 'Verify SSL',
                    placeholder: '',
                    help: 'Verify SSL cert',
                    required: false,
                },
                {
                    key: 'enabled',
                    type: 'toggle',
                    label: 'Enabled',
                    placeholder: '',
                    help: 'Enable or disable',
                    required: false,
                },
            ],
            capabilities: [{ name: 'dns-filtering', active: true }],
            logs: [],
        };

        it('renders select dropdown for select-remote fields', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () =>
                    Promise.resolve({
                        groups: [
                            { id: 0, name: 'Default', enabled: true },
                            { id: 1, name: 'Ad Blocking', enabled: true },
                        ],
                    }),
            });

            const wrapper = mountPage({ service: remoteService });

            // Wait for onMounted fetch to complete
            await vi.waitFor(() => {
                expect(wrapper.find('[data-testid="field-select-noblock_group_id"]').exists()).toBe(true);
            });
        });

        it('renders refresh button for select-remote fields', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () => Promise.resolve({ groups: [] }),
            });

            const wrapper = mountPage({ service: remoteService });

            expect(wrapper.find('[data-testid="field-refresh-noblock_group_id"]').exists()).toBe(true);
        });

        it('fetches remote options on mount for select-remote fields', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () =>
                    Promise.resolve({
                        groups: [
                            { id: 0, name: 'Default', enabled: true },
                            { id: 1, name: 'Ad Blocking', enabled: true },
                        ],
                    }),
            });

            mountPage({ service: remoteService });

            // Should have been called for the remote select field
            await vi.waitFor(() => {
                const remoteCalls = global.fetch.mock.calls.filter(
                    ([url]) => url === '/admin/settings/integrations/pihole/groups',
                );
                expect(remoteCalls.length).toBe(1);
            });
        });

        it('populates dropdown options after fetch', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () =>
                    Promise.resolve({
                        groups: [
                            { id: 0, name: 'Default', enabled: true },
                            { id: 1, name: 'Ad Blocking', enabled: true },
                        ],
                    }),
            });

            const wrapper = mountPage({ service: remoteService });

            await vi.waitFor(() => {
                const options = wrapper.findAll('[data-testid="field-select-noblock_group_id"] option');
                // 1 placeholder + 2 group options
                expect(options.length).toBe(3);
            });
        });

        it('shows error message when remote fetch fails', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () => Promise.resolve({ groups: [], error: 'Connection refused' }),
            });

            const wrapper = mountPage({ service: remoteService });

            await vi.waitFor(() => {
                expect(wrapper.text()).toContain('Connection refused');
            });
        });

        it('sends current form config when fetching remote options', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () => Promise.resolve({ groups: [] }),
            });

            mountPage({ service: remoteService });

            await vi.waitFor(() => {
                const remoteCalls = global.fetch.mock.calls.filter(
                    ([url]) => url === '/admin/settings/integrations/pihole/groups',
                );
                expect(remoteCalls.length).toBe(1);

                const [, options] = remoteCalls[0];
                expect(options.method).toBe('POST');
                const body = JSON.parse(options.body);
                expect(body).toHaveProperty('endpoint', 'https://pihole.test');
                expect(body).toHaveProperty('password', 'secret');
            });
        });

        it('re-fetches options when refresh button is clicked', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                json: () => Promise.resolve({ groups: [{ id: 0, name: 'Default' }] }),
            });

            const wrapper = mountPage({ service: remoteService });

            // Wait for initial fetch to complete and DOM to update
            await flushPromises();
            await wrapper.vm.$nextTick();

            const remoteCalls1 = global.fetch.mock.calls.filter(
                ([url]) => url === '/admin/settings/integrations/pihole/groups',
            );
            expect(remoteCalls1.length).toBe(1);

            // Click refresh button
            await wrapper.find('[data-testid="field-refresh-noblock_group_id"]').trigger('click');
            await flushPromises();
            await wrapper.vm.$nextTick();

            const remoteCalls2 = global.fetch.mock.calls.filter(
                ([url]) => url === '/admin/settings/integrations/pihole/groups',
            );
            expect(remoteCalls2.length).toBe(2);
        });
    });
});
