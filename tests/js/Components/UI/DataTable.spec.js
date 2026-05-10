import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
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

    it('navigates via keyboard space when clickable and rowHref', async () => {
        const rowHref = (row) => `/users/${row.id}`;
        router.visit.mockClear();
        const wrapper = mount(DataTable, {
            props: { columns, rows, clickable: true, rowHref },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        await wrapper.find('[data-testid="data-table-row"]').trigger('keydown.space');
        expect(router.visit).toHaveBeenCalledWith('/users/1');
    });

    it('adds accessible row label when row is interactive', () => {
        const rowHref = (row) => `/users/${row.id}`;
        const wrapper = mount(DataTable, {
            props: { columns, rows, clickable: true, rowHref },
            global: { stubs },
            slots: {
                row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
            },
        });
        const row = wrapper.find('[data-testid="data-table-row"]');
        expect(row.attributes('aria-label')).toBe('Open row 1');
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

    describe('Dispatch mockup styling', () => {
        it('table uses 13px font size and border-collapse', () => {
            const wrapper = mount(DataTable, {
                props: { columns, rows },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const table = wrapper.find('table');
            expect(table.classes()).toContain('text-[13px]');
            expect(table.classes()).toContain('border-collapse');
        });

        it('outer wrapper has no card styles (no bg, border, rounded, shadow)', () => {
            const wrapper = mount(DataTable, {
                props: { columns, rows },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const container = wrapper.find('[data-testid="data-table"]');
            const classList = container.classes();
            const hasCard = classList.some(
                (c) => c.startsWith('bg-') || c.startsWith('rounded') || c.startsWith('shadow') || c === 'border',
            );
            expect(hasCard).toBe(false);
        });

        it('header th has correct mockup classes', () => {
            const wrapper = mount(DataTable, {
                props: { columns, rows },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const th = wrapper.find('th');
            expect(th.classes()).toContain('text-[11px]');
            expect(th.classes()).toContain('font-semibold');
            expect(th.classes()).toContain('tracking-[0.05em]');
            expect(th.classes()).toContain('uppercase');
            expect(th.classes()).toContain('text-[var(--color-text-muted)]');
            expect(th.classes()).toContain('py-2');
        });

        it('header th border uses --color-border-hover (1px, not 2px)', () => {
            const wrapper = mount(DataTable, {
                props: { columns, rows },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const th = wrapper.find('th');
            expect(th.classes()).toContain('border-b');
            expect(th.classes()).toContain('border-[var(--color-border-hover)]');
            expect(th.classes()).not.toContain('border-b-2');
        });

        it('header tr has no border or background classes', () => {
            const wrapper = mount(DataTable, {
                props: { columns, rows },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const headerTr = wrapper.find('thead tr');
            const classList = headerTr.classes();
            const hasBorderOrBg = classList.some((c) => c.startsWith('border') || c.startsWith('bg-'));
            expect(hasBorderOrBg).toBe(false);
        });

        it('data rows have no inline border classes (borders are on td via CSS)', () => {
            const wrapper = mount(DataTable, {
                props: { columns, rows },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const row = wrapper.find('[data-testid="data-table-row"]');
            expect(row.classes()).toContain('transition-colors');
            expect(row.classes()).not.toContain('border-b');
            expect(row.classes()).not.toContain('border-[var(--color-border)]');
            expect(row.classes()).not.toContain('last:border-b-0');
        });

        it('rows have no hover:border-l-2 class', () => {
            const wrapper = mount(DataTable, {
                props: { columns, rows, clickable: true, rowHref: (row) => `/users/${row.id}` },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const row = wrapper.find('[data-testid="data-table-row"]');
            const classList = row.classes();
            const hasBorderLHover = classList.some((c) => c.includes('border-l'));
            expect(hasBorderLHover).toBe(false);
        });

        it('clickable rows use bg hover only', () => {
            const wrapper = mount(DataTable, {
                props: { columns, rows, clickable: true, rowHref: (row) => `/users/${row.id}` },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const row = wrapper.find('[data-testid="data-table-row"]');
            expect(row.classes()).toContain('hover:bg-[var(--color-surface-hover)]');
        });

        it('header th has no px-4 padding', () => {
            const wrapper = mount(DataTable, {
                props: { columns, rows },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const th = wrapper.find('th');
            expect(th.classes()).not.toContain('px-4');
        });
    });

    describe('Sortable columns', () => {
        const sortableColumns = [
            { key: 'name', label: 'Name', sortable: true },
            { key: 'email', label: 'Email' },
        ];

        it('renders sort button for sortable columns', () => {
            const wrapper = mount(DataTable, {
                props: { columns: sortableColumns, rows },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const sortButton = wrapper.find('[data-testid="sort-name"]');
            expect(sortButton.exists()).toBe(true);
            expect(sortButton.element.tagName).toBe('BUTTON');
        });

        it('does not render sort button for non-sortable columns', () => {
            const wrapper = mount(DataTable, {
                props: { columns: sortableColumns, rows },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            expect(wrapper.find('[data-testid="sort-email"]').exists()).toBe(false);
        });

        it('shows ascending arrow when column is active sort ascending', () => {
            const wrapper = mount(DataTable, {
                props: {
                    columns: sortableColumns,
                    rows,
                    sortColumn: 'name',
                    sortDirection: 'asc',
                },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const sortButton = wrapper.find('[data-testid="sort-name"]');
            expect(sortButton.text()).toContain('\u2191');
        });

        it('shows descending arrow when column is active sort descending', () => {
            const wrapper = mount(DataTable, {
                props: {
                    columns: sortableColumns,
                    rows,
                    sortColumn: 'name',
                    sortDirection: 'desc',
                },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const sortButton = wrapper.find('[data-testid="sort-name"]');
            expect(sortButton.text()).toContain('\u2193');
        });

        it('emits update:sort-column and update:sort-direction when clicking unsorted column', async () => {
            const wrapper = mount(DataTable, {
                props: {
                    columns: sortableColumns,
                    rows,
                    sortColumn: 'email',
                    sortDirection: 'asc',
                },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            await wrapper.find('[data-testid="sort-name"]').trigger('click');
            expect(wrapper.emitted('update:sort-column')).toEqual([['name']]);
            expect(wrapper.emitted('update:sort-direction')).toEqual([['asc']]);
        });

        it('emits update:sort-direction toggle when clicking already-sorted column', async () => {
            const wrapper = mount(DataTable, {
                props: {
                    columns: sortableColumns,
                    rows,
                    sortColumn: 'name',
                    sortDirection: 'asc',
                },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            await wrapper.find('[data-testid="sort-name"]').trigger('click');
            expect(wrapper.emitted('update:sort-column')).toBeUndefined();
            expect(wrapper.emitted('update:sort-direction')).toEqual([['desc']]);
        });

        it('sets aria-sort on sortable th', () => {
            const allSortable = [
                { key: 'name', label: 'Name', sortable: true },
                { key: 'email', label: 'Email', sortable: true },
            ];
            const wrapper = mount(DataTable, {
                props: {
                    columns: allSortable,
                    rows,
                    sortColumn: 'name',
                    sortDirection: 'asc',
                },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const headers = wrapper.findAll('th');
            expect(headers[0].attributes('aria-sort')).toBe('ascending');
            expect(headers[1].attributes('aria-sort')).toBe('none');
        });

        it('sort button has interactive styling (full-width, cursor, hover)', () => {
            const wrapper = mount(DataTable, {
                props: {
                    columns: sortableColumns,
                    rows,
                    sortColumn: 'name',
                    sortDirection: 'asc',
                },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const sortButton = wrapper.find('[data-testid="sort-name"]');
            expect(sortButton.classes()).toContain('flex');
            expect(sortButton.classes()).toContain('w-full');
            expect(sortButton.classes()).toContain('cursor-pointer');
            expect(sortButton.classes()).toContain('text-left');
            expect(sortButton.classes()).not.toContain('inline-flex');
        });

        it('sort indicator has ml-0.5 spacing', () => {
            const wrapper = mount(DataTable, {
                props: {
                    columns: sortableColumns,
                    rows,
                    sortColumn: 'name',
                    sortDirection: 'asc',
                },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td><td>${row.email}</td>`,
                },
            });
            const sortButton = wrapper.find('[data-testid="sort-name"]');
            const indicator = sortButton.find('span');
            expect(indicator.classes()).toContain('ml-0.5');
        });

        // --- Accessibility: focus indicator (WCAG 2.4.7) ---
        it('sort button does not have bare outline-none without a focus ring replacement', () => {
            const wrapper = mount(DataTable, {
                props: { columns: sortableColumns, rows },
                global: { stubs },
            });
            const sortBtn = wrapper.find('[data-testid="sort-name"]');
            expect(sortBtn.exists()).toBe(true);
            // Should not use bare outline-none that kills focus visibility
            expect(sortBtn.classes()).not.toContain('outline-none');
        });

        it('sort button has focus-visible ring classes for keyboard navigation', () => {
            const wrapper = mount(DataTable, {
                props: { columns: sortableColumns, rows },
                global: { stubs },
            });
            const sortBtn = wrapper.find('[data-testid="sort-name"]');
            const cls = sortBtn.classes().join(' ');
            expect(cls).toContain('focus-visible:ring-2');
        });
    });

    describe('Accessibility: focus indicators on clickable rows (WCAG 2.4.7)', () => {
        it('clickable row does not have bare focus-visible:outline-none without a ring', () => {
            const wrapper = mount(DataTable, {
                props: {
                    columns,
                    rows,
                    clickable: true,
                    rowHref: (row) => `/users/${row.id}`,
                },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td>`,
                },
            });
            const row = wrapper.find('[data-testid="data-table-row"]');
            const cls = row.classes().join(' ');
            // The row should NOT have bare focus-visible:outline-none (without a ring companion)
            expect(cls).not.toContain('focus-visible:outline-none');
        });

        it('clickable row has focus-visible ring for keyboard visibility', () => {
            const wrapper = mount(DataTable, {
                props: {
                    columns,
                    rows,
                    clickable: true,
                    rowHref: (row) => `/users/${row.id}`,
                },
                global: { stubs },
                slots: {
                    row: ({ row }) => `<td>${row.name}</td>`,
                },
            });
            const row = wrapper.find('[data-testid="data-table-row"]');
            const cls = row.classes().join(' ');
            expect(cls).toContain('focus-visible:ring-2');
        });
    });
});
