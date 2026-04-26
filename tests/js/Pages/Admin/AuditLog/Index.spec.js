import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '@/Pages/Admin/AuditLog/Index.vue';

const routeMock = vi.fn(() => '#');

const globalConfig = {
    stubs: ['AdminLayout', 'DataTable', 'FilterBar', 'Pagination', 'SectionHeader', 'Link'],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

describe('AuditLog/Index', () => {
    it('renders the page title', () => {
        const wrapper = mount(Index, {
            props: {
                logs: { data: [], total: 0 },
                filters: {},
                actionOptions: [],
                processOptions: [],
                subjectTypeOptions: [],
            },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Audit Log');
    });

    it('renders the audit log index layout', () => {
        const wrapper = mount(Index, {
            props: {
                logs: { data: [], total: 0 },
                filters: {},
                actionOptions: [],
                processOptions: [],
                subjectTypeOptions: [],
            },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="audit-log-index-layout"]').exists()).toBe(true);
    });

    it('renders table section', () => {
        const wrapper = mount(Index, {
            props: {
                logs: {
                    data: [
                        {
                            id: 1,
                            action: 'ip.created',
                            subject_type: 'IpAddress',
                            subject_id: 1,
                            related_type: null,
                            related_id: null,
                            actor_type: null,
                            actor_id: null,
                            process: 'scan_network',
                            metadata: null,
                            created_at: '2026-04-23T00:00:00+00:00',
                        },
                    ],
                    total: 1,
                },
                filters: {},
                actionOptions: ['ip.created'],
                processOptions: ['scan_network'],
                subjectTypeOptions: [],
            },
            global: {
                ...globalConfig,
                stubs: ['AdminLayout', 'FilterBar', 'Pagination', 'SectionHeader', 'Link'],
            },
        });
        expect(wrapper.find('[data-testid="audit-log-table-section"]').exists()).toBe(true);
    });

    it('renders filter bar', () => {
        const wrapper = mount(Index, {
            props: {
                logs: { data: [], total: 0 },
                filters: {},
                actionOptions: ['ip.created'],
                processOptions: ['scan_network'],
                subjectTypeOptions: [],
            },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="audit-log-filter-bar"]').exists()).toBe(true);
    });
});
