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
                request_method: 'GET',
                request_url: 'https://opnsense.example.com/api/diagnostics/system/system_time',
                response_status: 200,
                response_data: '{"status":"ok"}',
                tested_at: '2026-04-16T08:23:30+00:00',
            },
            {
                id: 2,
                success: false,
                message: 'Request failed',
                request_method: 'GET',
                request_url: 'https://opnsense.example.com/api/diagnostics/system/system_time',
                response_status: null,
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
        window.axios = {
            get: vi.fn().mockResolvedValue({ data: {} }),
            post: vi.fn().mockResolvedValue({ data: {} }),
            put: vi.fn().mockResolvedValue({ data: {} }),
            patch: vi.fn().mockResolvedValue({ data: {} }),
            delete: vi.fn().mockResolvedValue({ data: {} }),
        };
    });

    it('renders page title with service name', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('OPNsense');
    });

    it('shows health status', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="health-status"]').text()).toContain('Healthy');
    });

    it('renders Configuration section title', () => {
        const wrapper = mountPage();
        const headings = wrapper.findAll('h2');
        const configHeading = headings.find((h) => h.text() === 'Configuration');

        expect(configHeading).toBeDefined();
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
        window.axios.post.mockResolvedValue({
            data: { success: true, message: 'Connected successfully' },
        });

        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            expect.stringContaining('admin.settings.test'),
            expect.objectContaining({
                endpoint: 'https://opnsense.example.com',
                key: 'test-key',
                verify_ssl: '1',
            }),
            expect.any(Object),
        );
    });

    it('save button does not submit form on click (type=button)', () => {
        const wrapper = mountPage();
        const saveButton = wrapper.find('[data-testid="action-save"]');

        expect(saveButton.exists()).toBe(true);
        expect(saveButton.attributes('type')).toBe('button');
    });

    it('save button is in page header', () => {
        const wrapper = mountPage();
        const form = wrapper.find('[data-testid="config-form"]');
        const saveButton = wrapper.find('[data-testid="action-save"]');

        expect(saveButton.exists()).toBe(true);
        // The save button should not be a descendant of the form
        expect(form.find('[data-testid="action-save"]').exists()).toBe(false);
    });

    describe('test output toggle', () => {
        it('shows toggle button when test result has output', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: true,
                    message: 'Connected',
                    output: { status: 'ok' },
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-testid="test-output-toggle"]').exists()).toBe(true);
        });

        it('does not show toggle button when test result has no output', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: false,
                    message: 'Connection failed',
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-testid="test-output-toggle"]').exists()).toBe(false);
        });

        it('toggles output visibility when clicking Show/Hide Output', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: true,
                    message: 'Connected',
                    output: { status: 'ok' },
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            // details element exists
            const details = wrapper.find('[data-testid="test-output-details"]');
            expect(details.exists()).toBe(true);

            // Content is always in DOM when output is present
            expect(wrapper.find('[data-testid="test-output-content"]').exists()).toBe(true);

            // Initially closed (no open attribute)
            expect(details.attributes('open')).toBeUndefined();

            // Manually toggle open (native details behaviour)
            await details.element.setAttribute('open', '');
            await wrapper.vm.$nextTick();
            expect(details.attributes('open')).toBe('');

            // Manually toggle closed
            await details.element.removeAttribute('open');
            await wrapper.vm.$nextTick();
            expect(details.attributes('open')).toBeUndefined();
        });

        it('displays JSON output formatted', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: true,
                    message: 'Connected',
                    output: { status: 'ok', version: '1.0' },
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            const content = wrapper.find('[data-testid="test-output-content"]').text();
            expect(content).toContain('"status": "ok"');
            expect(content).toContain('"version": "1.0"');
        });

        it('displays string output as-is', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: true,
                    message: 'Connected',
                    output: 'plain text response',
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            const content = wrapper.find('[data-testid="test-output-content"]').text();
            expect(content).toBe('plain text response');
        });

        it('resets output toggle when running new test', async () => {
            window.axios.post
                .mockResolvedValueOnce({
                    data: {
                        success: true,
                        message: 'Connected',
                        output: { status: 'ok' },
                    },
                })
                .mockResolvedValueOnce({
                    data: {
                        success: true,
                        message: 'Connected again',
                        output: { status: 'ok2' },
                    },
                });

            const wrapper = mountPage();

            // First test
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="test-output-details"]').exists()).toBe(true);

            // Second test — details element re-rendered for new result
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="test-output-details"]').exists()).toBe(true);
        });
    });

    describe('test result panel styling', () => {
        it('test result panel has success styling', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: true,
                    message: 'Connected',
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            const panel = wrapper.find('[data-testid="test-result-panel"]');
            expect(panel.exists()).toBe(true);
            // Panel should carry a success-related class (bg or border)
            const classes = panel.classes().join(' ');
            expect(classes).toMatch(/success/);
        });

        it('test result panel has danger styling', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: false,
                    message: 'Connection failed',
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            const panel = wrapper.find('[data-testid="test-result-panel"]');
            expect(panel.exists()).toBe(true);
            const classes = panel.classes().join(' ');
            expect(classes).toMatch(/danger/);
        });
    });

    describe('test request/response detail', () => {
        it('shows request method and URL when test result has request_method', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: true,
                    message: 'Connected',
                    request_method: 'GET',
                    request_url: 'https://opnsense.local/api/diagnostics/system/system_time',
                    response_status: 200,
                    output: { status: 'ok' },
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            const detail = wrapper.find('[data-testid="test-request-detail"]');
            expect(detail.exists()).toBe(true);
            expect(detail.text()).toContain('GET');
            expect(detail.text()).toContain('https://opnsense.local/api/diagnostics/system/system_time');
        });

        it('shows response status when present', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: true,
                    message: 'Connected',
                    request_method: 'POST',
                    request_url: 'https://pihole.local/api/auth',
                    response_status: 200,
                    output: { session: {} },
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            const detail = wrapper.find('[data-testid="test-request-detail"]');
            expect(detail.exists()).toBe(true);
            expect(detail.text()).toContain('200');
            expect(detail.text()).toContain('Status');
        });

        it('does not show request detail when request_method is absent', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: false,
                    message: 'Connection failed',
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-testid="test-request-detail"]').exists()).toBe(false);
        });

        it('does not show response status for failed tests without status', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    success: false,
                    message: 'Connection failed',
                    request_method: 'GET',
                    request_url: 'https://opnsense.local/api/test',
                },
            });

            const wrapper = mountPage();
            await wrapper.find('[data-testid="action-test-connection"]').trigger('click');
            await flushPromises();

            const detail = wrapper.find('[data-testid="test-request-detail"]');
            expect(detail.exists()).toBe(true);
            expect(detail.text()).not.toContain('Status');
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

        it('displays request method and URL in health log row', () => {
            const wrapper = mountPage();

            const row = wrapper.find('[data-testid="health-log-row-0"]');
            expect(row.text()).toContain('GET');
            expect(row.text()).toContain('https://opnsense.example.com/api/diagnostics/system/system_time');
        });

        it('displays response status in health log row', () => {
            const wrapper = mountPage();

            const row = wrapper.find('[data-testid="health-log-row-0"]');
            expect(row.text()).toContain('200');
        });

        it('displays dash when request method is absent in health log', () => {
            const wrapper = mountPage({
                service: {
                    logs: [
                        {
                            id: 10,
                            success: true,
                            message: 'Legacy log',
                            request_method: null,
                            request_url: null,
                            response_status: null,
                            response_data: null,
                            tested_at: '2026-04-16T08:00:00+00:00',
                        },
                    ],
                },
            });

            const row = wrapper.find('[data-testid="health-log-row-0"]');
            expect(row.text()).toContain('—');
        });

        it('renders Request and Response column headers', () => {
            const wrapper = mountPage();
            const table = wrapper.find('[data-testid="health-log-table"]');

            expect(table.text()).toContain('Request');
            expect(table.text()).toContain('Response');
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
            window.axios.post.mockResolvedValue({
                data: {
                    groups: [
                        { id: 0, name: 'Default', enabled: true },
                        { id: 1, name: 'Ad Blocking', enabled: true },
                    ],
                },
            });

            const wrapper = mountPage({ service: remoteService });

            // Wait for onMounted fetch to complete
            await vi.waitFor(() => {
                expect(wrapper.find('[data-testid="field-select-noblock_group_id"]').exists()).toBe(true);
            });
        });

        it('renders refresh button for select-remote fields', async () => {
            window.axios.post.mockResolvedValue({ data: { groups: [] } });

            const wrapper = mountPage({ service: remoteService });

            expect(wrapper.find('[data-testid="field-refresh-noblock_group_id"]').exists()).toBe(true);
        });

        it('fetches remote options on mount for select-remote fields', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    groups: [
                        { id: 0, name: 'Default', enabled: true },
                        { id: 1, name: 'Ad Blocking', enabled: true },
                    ],
                },
            });

            mountPage({ service: remoteService });

            // Should have been called for the remote select field
            await vi.waitFor(() => {
                const remoteCalls = window.axios.post.mock.calls.filter(
                    ([url]) => url === '/admin/settings/integrations/pihole/groups',
                );
                expect(remoteCalls.length).toBe(1);
            });
        });

        it('populates dropdown options after fetch', async () => {
            window.axios.post.mockResolvedValue({
                data: {
                    groups: [
                        { id: 0, name: 'Default', enabled: true },
                        { id: 1, name: 'Ad Blocking', enabled: true },
                    ],
                },
            });

            const wrapper = mountPage({ service: remoteService });

            await vi.waitFor(() => {
                const options = wrapper.findAll('[data-testid="field-select-noblock_group_id"] option');
                // 1 placeholder + 2 group options
                expect(options.length).toBe(3);
            });
        });

        it('shows error message when remote fetch fails', async () => {
            window.axios.post.mockResolvedValue({ data: { groups: [], error: 'Connection refused' } });

            const wrapper = mountPage({ service: remoteService });

            await vi.waitFor(() => {
                expect(wrapper.text()).toContain('Connection refused');
            });
        });

        it('sends current form config when fetching remote options', async () => {
            window.axios.post.mockResolvedValue({ data: { groups: [] } });

            mountPage({ service: remoteService });

            await vi.waitFor(() => {
                const remoteCalls = window.axios.post.mock.calls.filter(
                    ([url]) => url === '/admin/settings/integrations/pihole/groups',
                );
                expect(remoteCalls.length).toBe(1);

                const [, data] = remoteCalls[0];
                expect(data).toHaveProperty('endpoint', 'https://pihole.test');
                expect(data).toHaveProperty('password', 'secret');
            });
        });

        it('re-fetches options when refresh button is clicked', async () => {
            window.axios.post.mockResolvedValue({
                data: { groups: [{ id: 0, name: 'Default' }] },
            });

            const wrapper = mountPage({ service: remoteService });

            // Wait for initial fetch to complete and DOM to update
            await flushPromises();
            await wrapper.vm.$nextTick();

            const remoteCalls1 = window.axios.post.mock.calls.filter(
                ([url]) => url === '/admin/settings/integrations/pihole/groups',
            );
            expect(remoteCalls1.length).toBe(1);

            // Click refresh button
            await wrapper.find('[data-testid="field-refresh-noblock_group_id"]').trigger('click');
            await flushPromises();
            await wrapper.vm.$nextTick();

            const remoteCalls2 = window.axios.post.mock.calls.filter(
                ([url]) => url === '/admin/settings/integrations/pihole/groups',
            );
            expect(remoteCalls2.length).toBe(2);
        });
    });

    describe('select fields', () => {
        const selectService = {
            id: 'opnsense',
            name: 'OPNsense',
            description: 'Firewall integration',
            health: true,
            config: {
                endpoint: 'https://opnsense.example.com',
                dhcp_server: 'isc',
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
                    key: 'dhcp_server',
                    type: 'select',
                    label: 'DHCP Server',
                    help: 'Select the DHCP server plugin.',
                    required: false,
                    options: {
                        '': 'None',
                        isc: 'ISC DHCPD',
                        kea: 'Kea DHCP',
                        dnsmasq: 'Dnsmasq',
                    },
                },
            ],
            capabilities: [{ name: 'dhcp', active: true }],
            logs: [],
        };

        it('renders select dropdown for select-type fields', () => {
            const wrapper = mountPage({ service: selectService });
            const select = wrapper.find('[data-testid="field-dhcp_server"]');

            expect(select.exists()).toBe(true);
            expect(select.element.tagName).toBe('SELECT');
        });

        it('renders all static options', () => {
            const wrapper = mountPage({ service: selectService });
            const options = wrapper.findAll('[data-testid="field-dhcp_server"] option');

            expect(options.length).toBe(4);
            expect(options[0].text()).toBe('None');
            expect(options[0].element.value).toBe('');
            expect(options[1].text()).toBe('ISC DHCPD');
            expect(options[1].element.value).toBe('isc');
            expect(options[2].text()).toBe('Kea DHCP');
            expect(options[2].element.value).toBe('kea');
            expect(options[3].text()).toBe('Dnsmasq');
            expect(options[3].element.value).toBe('dnsmasq');
        });

        it('binds selected value from config', () => {
            const wrapper = mountPage({ service: selectService });
            const select = wrapper.find('[data-testid="field-dhcp_server"]');

            expect(select.element.value).toBe('isc');
        });

        it('does not render refresh button for static select', () => {
            const wrapper = mountPage({ service: selectService });

            expect(wrapper.find('[data-testid="field-refresh-dhcp_server"]').exists()).toBe(false);
        });

        it('displays help text for select field', () => {
            const wrapper = mountPage({ service: selectService });
            const helpTexts = wrapper.findAll('.text-xs.text-\\[var\\(--color-text-muted\\)\\]');
            const helpContents = helpTexts.map((el) => el.text());

            expect(helpContents).toContain('Select the DHCP server plugin.');
        });
    });
});
