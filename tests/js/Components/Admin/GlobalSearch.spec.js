import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import GlobalSearch from '@/Components/Admin/GlobalSearch.vue';

const mockVisit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: (...args) => mockVisit(...args) },
}));

vi.stubGlobal(
    'route',
    vi.fn((name, param) => {
        if (name === 'admin.search') return '/admin/search';
        if (name === 'admin.users.show') return `/admin/users/${param}`;
        if (name === 'admin.ips.show') return `/admin/ips/${param}`;
        return '/';
    }),
);

function mockFetchSuccess(data) {
    const mock = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve(data),
    });
    vi.stubGlobal('fetch', mock);
    return mock;
}

function mountGlobalSearch() {
    return mount(GlobalSearch, { attachTo: document.body });
}

describe('GlobalSearch.vue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        mockVisit.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('trigger button', () => {
        it('renders the search trigger with data-testid', () => {
            const wrapper = mountGlobalSearch();
            const trigger = wrapper.get('[data-testid="global-search-trigger"]');

            expect(trigger.exists()).toBe(true);
            expect(trigger.text()).toContain('Search...');
        });

        it('has Dispatch design system styling on the trigger button', () => {
            const wrapper = mountGlobalSearch();
            const trigger = wrapper.get('[data-testid="global-search-trigger"]');

            expect(trigger.classes()).toContain('text-[12px]');
            expect(trigger.classes()).toContain('bg-[var(--color-surface)]');
            expect(trigger.classes()).toContain('border-[var(--color-border)]');
            expect(trigger.classes()).toContain('rounded');
        });

        it('renders the keyboard shortcut badge with correct Dispatch styling', () => {
            const wrapper = mountGlobalSearch();
            const kbd = wrapper.get('[data-testid="global-search-shortcut"]');

            expect(kbd.text()).toBe('⌘K');
            expect(kbd.classes()).toContain('font-mono');
            expect(kbd.classes()).toContain('text-[10px]');
            expect(kbd.classes()).toContain('border-[var(--color-border-hover)]');
            expect(kbd.classes()).toContain('text-[var(--color-text-muted)]');
            expect(kbd.classes()).toContain('inline-flex');
            expect(kbd.classes()).toContain('items-center');
            expect(kbd.classes()).toContain('gap-1');
            expect(kbd.classes()).toContain('rounded');
        });

        it('opens the dialog when trigger is clicked', async () => {
            const wrapper = mountGlobalSearch();

            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(false);

            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');

            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(true);
        });
    });

    describe('keyboard shortcuts', () => {
        it('opens the dialog with Cmd+K', async () => {
            const wrapper = mountGlobalSearch();

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
            await nextTick();

            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(true);
        });

        it('opens the dialog with Ctrl+K', async () => {
            const wrapper = mountGlobalSearch();

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
            await nextTick();

            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(true);
        });

        it('closes the dialog with Escape', async () => {
            const wrapper = mountGlobalSearch();

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
            await nextTick();
            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(true);

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
            await nextTick();
            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(false);
        });

        it('toggles the dialog with repeated Cmd+K', async () => {
            const wrapper = mountGlobalSearch();

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
            await nextTick();
            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(true);

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
            await nextTick();
            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(false);
        });
    });

    describe('dialog styling', () => {
        it('applies Dispatch surface background and border to the dialog', async () => {
            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');

            const dialog = wrapper.get('[data-testid="global-search-dialog"]');

            expect(dialog.classes()).toContain('bg-[var(--color-surface)]');
            expect(dialog.classes()).toContain('border-[var(--color-border)]');
            expect(dialog.classes()).toContain('rounded');
        });

        it('renders the search input with Dispatch styling', async () => {
            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');

            const input = wrapper.get('[data-testid="global-search-input"]');

            expect(input.classes()).toContain('text-[13px]');
            expect(input.classes()).toContain('bg-[var(--color-surface)]');
            expect(input.classes()).toContain('border-[var(--color-border-hover)]');
            expect(input.classes()).toContain('text-[var(--color-text)]');
            expect(input.classes()).toContain('placeholder:text-[var(--color-text-muted)]');
            expect(input.classes()).toContain('focus:border-[var(--color-primary)]');
            expect(input.classes()).toContain('pl-8');
            expect(input.classes()).toContain('py-[7px]');
        });

        it('renders a search icon inside the dialog', async () => {
            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');

            const svg = wrapper.get('[data-testid="global-search-dialog"] svg');

            expect(svg.exists()).toBe(true);
        });
    });

    describe('search behaviour', () => {
        it('does not search when query is less than 2 characters', async () => {
            const fetchMock = mockFetchSuccess({ users: [], ips: [] });
            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');

            const input = wrapper.get('[data-testid="global-search-input"]');
            await input.setValue('a');
            vi.advanceTimersByTime(300);
            await flushPromises();

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it('debounces and fetches search results for queries with 2+ characters', async () => {
            const fetchMock = mockFetchSuccess({
                users: [{ id: 1, nickname: 'alice', email: 'alice@test.com' }],
                ips: [],
            });

            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');

            const input = wrapper.get('[data-testid="global-search-input"]');
            await input.setValue('al');
            vi.advanceTimersByTime(300);
            await flushPromises();

            expect(fetchMock).toHaveBeenCalledWith('/admin/search?q=al');
        });

        it('displays user results with data-testid attributes', async () => {
            mockFetchSuccess({
                users: [{ id: 42, nickname: 'bob', email: 'bob@test.com' }],
                ips: [],
            });

            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');
            await wrapper.get('[data-testid="global-search-input"]').setValue('bo');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            const section = wrapper.get('[data-testid="global-search-section-users"]');
            expect(section.text()).toBe('Users');

            const result = wrapper.get('[data-testid="global-search-result-user-42"]');
            expect(result.text()).toContain('bob');
            expect(result.text()).toContain('bob@test.com');
            expect(result.classes()).toContain('text-[13px]');
            expect(result.classes()).toContain('hover:bg-[var(--color-surface-hover)]');
            expect(result.classes()).toContain('rounded');
        });

        it('displays IP results with data-testid attributes', async () => {
            mockFetchSuccess({
                users: [],
                ips: [{ id: 7, address: '10.0.0.1' }],
            });

            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');
            await wrapper.get('[data-testid="global-search-input"]').setValue('10.');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            const section = wrapper.get('[data-testid="global-search-section-ips"]');
            expect(section.text()).toBe('IP Addresses');

            const result = wrapper.get('[data-testid="global-search-result-ip-7"]');
            expect(result.text()).toContain('10.0.0.1');
            expect(result.classes()).toContain('text-[13px]');
            expect(result.classes()).toContain('font-mono');
            expect(result.classes()).toContain('rounded');
        });

        it('shows empty state when no results found', async () => {
            mockFetchSuccess({ users: [], ips: [] });

            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');
            await wrapper.get('[data-testid="global-search-input"]').setValue('zzz');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            const empty = wrapper.get('[data-testid="global-search-empty"]');
            expect(empty.text()).toBe('No results found.');
            expect(empty.classes()).toContain('text-[13px]');
            expect(empty.classes()).toContain('text-[var(--color-text-muted)]');
        });

        it('handles fetch errors silently', async () => {
            vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('Network error')));

            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');
            await wrapper.get('[data-testid="global-search-input"]').setValue('err');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            expect(wrapper.find('[data-testid="global-search-section-users"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="global-search-section-ips"]').exists()).toBe(false);
        });
    });

    describe('navigation', () => {
        it('navigates to user and closes dialog on user result click', async () => {
            mockFetchSuccess({
                users: [{ id: 5, nickname: 'carol', email: 'carol@test.com' }],
                ips: [],
            });

            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');
            await wrapper.get('[data-testid="global-search-input"]').setValue('carol');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            await wrapper.get('[data-testid="global-search-result-user-5"]').trigger('click');
            await nextTick();

            expect(mockVisit).toHaveBeenCalledWith('/admin/users/5');
            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(false);
        });

        it('navigates to IP and closes dialog on IP result click', async () => {
            mockFetchSuccess({
                users: [],
                ips: [{ id: 3, address: '192.168.1.1' }],
            });

            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');
            await wrapper.get('[data-testid="global-search-input"]').setValue('192');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            await wrapper.get('[data-testid="global-search-result-ip-3"]').trigger('click');
            await nextTick();

            expect(mockVisit).toHaveBeenCalledWith('/admin/ips/192.168.1.1');
            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(false);
        });
    });

    describe('overlay interaction', () => {
        it('closes the dialog when clicking the overlay backdrop', async () => {
            const wrapper = mountGlobalSearch();
            await wrapper.get('[data-testid="global-search-trigger"]').trigger('click');
            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(true);

            await wrapper.get('[data-testid="global-search-overlay"]').trigger('click');
            await nextTick();

            expect(wrapper.find('[data-testid="global-search-overlay"]').exists()).toBe(false);
        });
    });
});
