import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import DataTable from '@/Components/UI/DataTable.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn() },
}));

import { router } from '@inertiajs/vue3';

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
];

const rows = [
    { id: 1, name: 'Alice', email: 'alice@example.com' },
    { id: 2, name: 'Bob', email: 'bob@example.com' },
];

const stubs = { Link: { template: '<a><slot /></a>', props: ['href'] } };

describe('DataTable', () => {
    it('renders with required props', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows },
            global: { stubs },
        });
        expect(wrapper.find('[data-testid="data-table"]').exists()).toBe(true);
    });

    it('renders column headers', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows },
            global: { stubs },
        });
        const headers = wrapper.findAll('th');
        expect(headers).toHaveLength(2);
        expect(headers[0].text()).toBe('Name');
        expect(headers[1].text()).toBe('Email');
    });

    it('renders rows via slot', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        const tableRows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(tableRows).toHaveLength(2);
    });

    it('shows empty message when no rows', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows: [] },
            global: { stubs },
        });
        const empty = wrapper.find('[data-testid="data-table-empty"]');
        expect(empty.exists()).toBe(true);
        expect(empty.text()).toBe('No records found.');
    });

    it('shows custom empty message', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows: [], emptyMessage: 'Nothing here.' },
            global: { stubs },
        });
        expect(wrapper.find('[data-testid="data-table-empty"]').text()).toBe('Nothing here.');
    });

    it('applies sr-only class to columns with srOnly flag', () => {
        const cols = [
            { key: 'name', label: 'Name' },
            { key: 'actions', label: 'Actions', srOnly: true },
        ];
        const wrapper = mount(DataTable, {
            props: { columns: cols, rows: [] },
            global: { stubs },
        });
        const headers = wrapper.findAll('th');
        expect(headers[1].classes()).toContain('sr-only');
    });

    it('applies clickable styles when clickable prop is true', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows, clickable: true },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        const row = wrapper.find('[data-testid="data-table-row"]');
        expect(row.classes()).toContain('cursor-pointer');
    });

    it('does not apply clickable styles by default', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        const row = wrapper.find('[data-testid="data-table-row"]');
        expect(row.classes()).not.toContain('cursor-pointer');
    });

    it('applies rowClass function to data rows', () => {
        const rowClass = (row) => (row.name === 'Alice' ? 'highlight' : 'dim');
        const wrapper = mount(DataTable, {
            props: { columns, rows, rowClass },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        const tableRows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(tableRows[0].classes()).toContain('highlight');
        expect(tableRows[1].classes()).toContain('dim');
    });

    it('does not add extra classes when rowClass is null', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        const row = wrapper.find('[data-testid="data-table-row"]');
        expect(row.classes()).not.toContain('highlight');
        expect(row.classes()).not.toContain('dim');
    });

    it('sets correct colspan on empty row', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows: [] },
            global: { stubs },
        });
        const td = wrapper.find('[data-testid="data-table-empty"] td');
        expect(td.attributes('colspan')).toBe('2');
    });

    it('navigates via router.visit on row click when clickable and rowHref', async () => {
        const rowHref = (row) => `/users/${row.id}`;
        router.visit.mockClear();
        const wrapper = mount(DataTable, {
            props: { columns, rows, clickable: true, rowHref },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        await wrapper.find('[data-testid="data-table-row"]').trigger('click');
        expect(router.visit).toHaveBeenCalledWith('/users/1');
    });

    it('navigates via keyboard enter when clickable and rowHref', async () => {
        const rowHref = (row) => `/users/${row.id}`;
        router.visit.mockClear();
        const wrapper = mount(DataTable, {
            props: { columns, rows, clickable: true, rowHref },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        await wrapper.find('[data-testid="data-table-row"]').trigger('keydown.enter');
        expect(router.visit).toHaveBeenCalledWith('/users/1');
    });

    it('does not navigate when clickable is false', async () => {
        const rowHref = (row) => `/users/${row.id}`;
        router.visit.mockClear();
        const wrapper = mount(DataTable, {
            props: { columns, rows, rowHref },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        await wrapper.find('[data-testid="data-table-row"]').trigger('click');
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('does not navigate when rowHref is null', async () => {
        router.visit.mockClear();
        const wrapper = mount(DataTable, {
            props: { columns, rows, clickable: true },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        await wrapper.find('[data-testid="data-table-row"]').trigger('click');
        expect(router.visit).not.toHaveBeenCalled();
    });
});
