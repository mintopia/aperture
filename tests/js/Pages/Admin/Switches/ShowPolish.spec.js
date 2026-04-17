import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Show from '@/Pages/Admin/Switches/Show.vue';
import { router } from '@inertiajs/vue3';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
    },
    Link: {
        template: '<a><slot /></a>',
        props: ['href'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.mock('@/utils/dates', () => ({
    formatRelative: vi.fn((v) => v),
    formatDate: vi.fn((v) => v),
}));

vi.mock('@/utils/switches', () => ({
    typeLabel: vi.fn((v) => v),
    statusLabel: vi.fn((v) => v ?? '—'),
}));

// Set up global route function for script setup
globalThis.route = (...args) => `/mocked/${args[0]}`;

const defaultProps = {
    switchConfig: {
        id: 1,
        name: 'Core Switch',
        hostname: '10.0.0.1',
        port: 22,
        type: 'cisco_ios',
        enabled: true,
        timeout: 30,
        last_synced_at: '2024-01-01T00:00:00Z',
        created_at: '2024-01-01T00:00:00Z',
    },
    ports: [],
    canDownloadConfig: false,
    runningConfig: '',
    latestSync: null,
};

function mountShow(propsOverride = {}) {
    return mount(Show, {
        props: { ...defaultProps, ...propsOverride },
        global: {
            mocks: {
                route: (...args) => `/mocked/${args[0]}`,
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                MetadataStrip: { template: '<div />', props: ['items'] },
                SectionHeader: { template: '<div><slot /></div>', props: ['title'] },
                StatusPill: {
                    template: '<span data-testid="status-pill">{{ label }}</span>',
                    props: ['status', 'label'],
                },
                ConfigBlock: { template: '<div />', props: ['code'] },
                // Don't stub ConfirmModal - let it render for real
                teleport: true,
            },
        },
    });
}

describe('Show.vue - Polish', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Delete functionality with ConfirmModal', () => {
        it('renders ConfirmModal for delete', async () => {
            const wrapper = mountShow();

            // Click delete button
            const deleteButton = wrapper.find('[data-testid="action-delete"]');
            expect(deleteButton.exists()).toBe(true);
            await deleteButton.trigger('click');
            await flushPromises();

            // Expect ConfirmModal to be visible
            expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
        });

        it('delete confirm triggers router.delete', async () => {
            const wrapper = mountShow();

            // Click delete button
            await wrapper.find('[data-testid="action-delete"]').trigger('click');
            await flushPromises();

            // Click confirm in modal
            const confirmButton = wrapper.find('[data-testid="confirm-modal-confirm"]');
            expect(confirmButton.exists()).toBe(true);
            await confirmButton.trigger('click');
            await flushPromises();

            // Expect router.delete to have been called
            expect(router.delete).toHaveBeenCalledWith(
                '/mocked/admin.switches.destroy',
                expect.objectContaining({
                    onFinish: expect.any(Function),
                }),
            );
        });

        it('cancel closes the modal', async () => {
            const wrapper = mountShow();

            // Click delete button
            await wrapper.find('[data-testid="action-delete"]').trigger('click');
            await flushPromises();

            // Modal should be visible
            expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);

            // Click cancel
            const cancelButton = wrapper.find('[data-testid="confirm-modal-cancel"]');
            expect(cancelButton.exists()).toBe(true);
            await cancelButton.trigger('click');
            await flushPromises();

            // Modal should be gone
            expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);

            // router.delete should NOT have been called
            expect(router.delete).not.toHaveBeenCalled();
        });

        it('shows loading state during delete', async () => {
            // Make router.delete hang - don't call onFinish immediately
            vi.mocked(router.delete).mockImplementation((_url, _options) => {
                // Don't call onFinish - simulating an in-progress request
            });

            const wrapper = mountShow();

            // Click delete button
            await wrapper.find('[data-testid="action-delete"]').trigger('click');
            await flushPromises();

            // Click confirm
            let confirmButton = wrapper.find('[data-testid="confirm-modal-confirm"]');
            await confirmButton.trigger('click');
            await flushPromises();
            await wrapper.vm.$nextTick();

            // Re-find button after state change
            confirmButton = wrapper.find('[data-testid="confirm-modal-confirm"]');

            // Confirm button should show loading state
            // ConfirmModal appends "…" to confirmLabel when loading=true
            expect(confirmButton.text()).toBe('Delete Switch…');
        });
    });

    describe('Action button tooltips', () => {
        it('action buttons have title attributes', () => {
            const wrapper = mountShow();

            // Check sync button tooltip
            const syncButton = wrapper.find('[data-testid="action-sync"]');
            expect(syncButton.exists()).toBe(true);
            expect(syncButton.attributes('title')).toBe('Trigger a port sync from the switch');

            // Check test button tooltip
            const testButton = wrapper.find('[data-testid="action-test"]');
            expect(testButton.exists()).toBe(true);
            expect(testButton.attributes('title')).toBe('Test SSH connectivity to this switch');

            // Check delete button tooltip
            const deleteButton = wrapper.find('[data-testid="action-delete"]');
            expect(deleteButton.exists()).toBe(true);
            expect(deleteButton.attributes('title')).toBe('Remove this switch and all its data');
        });
    });
});
