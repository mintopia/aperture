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
        it('renders the search trigger button', () => {
            const wrapper = mountGlobalSearch();
            const trigger = wrapper.find('button');

            expect(trigger.exists()).toBe(true);
            expect(trigger.text()).toContain('Search...');
        });

        it('has styling on the trigger button', () => {
            const wrapper = mountGlobalSearch();
            const trigger = wrapper.find('button');

            expect(trigger.classes()).toContain('text-sm');
            expect(trigger.classes()).toContain('bg-[var(--color-input-bg)]');
            expect(trigger.classes()).toContain('border-[var(--color-border)]');
            expect(trigger.classes()).toContain('rounded-lg');
        });

        it('renders the keyboard shortcut badge', () => {
            const wrapper = mountGlobalSearch();
            const kbd = wrapper.find('kbd');

            expect(kbd.text()).toBe('⌘K');
            expect(kbd.classes()).toContain('text-xs');
            expect(kbd.classes()).toContain('border');
            expect(kbd.classes()).toContain('border-[var(--color-border)]');
            expect(kbd.classes()).toContain('rounded');
        });

        it('opens the dialog when trigger is clicked', async () => {
            const wrapper = mountGlobalSearch();

            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(false);

            await wrapper.find('button').trigger('click');

            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(true);
        });
    });

    describe('keyboard shortcuts', () => {
        it('opens the dialog with Cmd+K', async () => {
            const wrapper = mountGlobalSearch();

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
            await nextTick();

            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(true);
        });

        it('opens the dialog with Ctrl+K', async () => {
            const wrapper = mountGlobalSearch();

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
            await nextTick();

            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(true);
        });

        it('closes the dialog with Escape', async () => {
            const wrapper = mountGlobalSearch();

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
            await nextTick();
            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(true);

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
            await nextTick();
            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(false);
        });

        it('toggles the dialog with repeated Cmd+K', async () => {
            const wrapper = mountGlobalSearch();

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
            await nextTick();
            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(true);

            await document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
            await nextTick();
            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(false);
        });
    });

    describe('dialog styling', () => {
        it('applies background and border to the dialog panel', async () => {
            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');

            const dialog = wrapper.find('.relative.w-full.max-w-lg');

            expect(dialog.classes()).toContain('bg-[var(--color-bg)]');
            expect(dialog.classes()).toContain('border-[var(--color-border)]');
            expect(dialog.classes()).toContain('rounded-xl');
        });

        it('renders the search input with correct styling', async () => {
            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');

            const input = wrapper.find('input');

            expect(input.classes()).toContain('text-sm');
            expect(input.classes()).toContain('bg-transparent');
            expect(input.classes()).toContain('border-[var(--color-border)]');
            expect(input.classes()).toContain('text-[var(--color-text)]');
            expect(input.classes()).toContain('outline-none');
        });

        it('renders the backdrop overlay', async () => {
            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');

            const backdrop = wrapper.find('.fixed.inset-0.bg-\\[oklch\\(12\\%_0\\.006_60_\\/_0\\.5\\)\\]');

            expect(backdrop.exists()).toBe(true);
        });
    });

    describe('search behaviour', () => {
        it('does not search when query is less than 2 characters', async () => {
            const fetchMock = mockFetchSuccess({ users: [], ips: [] });
            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');

            const input = wrapper.find('input');
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
            await wrapper.find('button').trigger('click');

            const input = wrapper.find('input');
            await input.setValue('al');
            vi.advanceTimersByTime(300);
            await flushPromises();

            expect(fetchMock).toHaveBeenCalledWith('/admin/search?q=al');
        });

        it('displays user results', async () => {
            mockFetchSuccess({
                users: [{ id: 42, nickname: 'bob', email: 'bob@test.com' }],
                ips: [],
            });

            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');
            await wrapper.find('input').setValue('bo');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            const html = wrapper.html();
            expect(html).toContain('Users');
            expect(html).toContain('bob');
            expect(html).toContain('bob@test.com');
        });

        it('displays IP results', async () => {
            mockFetchSuccess({
                users: [],
                ips: [{ id: 7, address: '10.0.0.1' }],
            });

            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');
            await wrapper.find('input').setValue('10.');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            const html = wrapper.html();
            expect(html).toContain('IP Addresses');
            expect(html).toContain('10.0.0.1');
        });

        it('shows empty state when no results found', async () => {
            mockFetchSuccess({ users: [], ips: [] });

            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');
            await wrapper.find('input').setValue('zzz');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            expect(wrapper.html()).toContain('No results found.');
        });

        it('handles fetch errors silently', async () => {
            vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('Network error')));

            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');
            await wrapper.find('input').setValue('err');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            expect(wrapper.html()).not.toContain('Users');
            expect(wrapper.html()).not.toContain('IP Addresses');
        });
    });

    describe('navigation', () => {
        it('navigates to user and closes dialog on user result click', async () => {
            mockFetchSuccess({
                users: [{ id: 5, nickname: 'carol', email: 'carol@test.com' }],
                ips: [],
            });

            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');
            await wrapper.find('input').setValue('carol');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            // Find the user result button (inside the users section)
            const userButtons = wrapper.findAll('.max-h-80 button');
            await userButtons[0].trigger('click');
            await nextTick();

            expect(mockVisit).toHaveBeenCalledWith('/admin/users/5');
            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(false);
        });

        it('navigates to IP and closes dialog on IP result click', async () => {
            mockFetchSuccess({
                users: [],
                ips: [{ id: 3, address: '192.168.1.1' }],
            });

            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');
            await wrapper.find('input').setValue('192');
            vi.advanceTimersByTime(300);
            await flushPromises();
            await nextTick();

            // Find the IP result button
            const ipButtons = wrapper.findAll('.max-h-80 button');
            await ipButtons[0].trigger('click');
            await nextTick();

            expect(mockVisit).toHaveBeenCalledWith('/admin/ips/192.168.1.1');
            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(false);
        });
    });

    describe('overlay interaction', () => {
        it('closes the dialog when clicking the overlay wrapper', async () => {
            const wrapper = mountGlobalSearch();
            await wrapper.find('button').trigger('click');
            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(true);

            // Click the outer wrapper (which has @click.self="open = false")
            await wrapper.find('.fixed.inset-0.z-50').trigger('click');
            await nextTick();

            expect(wrapper.find('.fixed.inset-0.z-50').exists()).toBe(false);
        });
    });
});
