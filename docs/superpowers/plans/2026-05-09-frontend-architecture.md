# Frontend Architecture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Three frontend architecture improvements: extract a shared `useBandwidthChart()` composable eliminating duplication across 3 pages, and standardise the frontend HTTP layer with a shared `useApi()` composable for raw `fetch` calls.

**Architecture:** Composables in `resources/js/composables/`. All pages using bandwidth polling share the same composable. The `useApi()` composable wraps `window.axios` (already configured with CSRF) for non-Inertia JSON requests.

**Tech Stack:** Vue 3 Composition API, @inertiajs/vue3, axios (already configured with CSRF in bootstrap.js), Vitest, @vue/test-utils

---

## Task 1: Extract useBandwidthChart() composable

**Problem:** The same bandwidth fetch/poll/range-selection/chart-series logic is duplicated in three files:
- `resources/js/Pages/Admin/Users/Show.vue` (lines 178–232)
- `resources/js/Pages/Admin/Ips/Show.vue` (lines 93–196)
- `resources/js/Pages/Admin/Dashboard.vue` (lines 65–190)

All three have:
```js
const selectedRange = ref('24h');
const bandwidthData = ref({ timestamps: [], download: [], upload: [], totalReceived: 0, totalSent: 0 });
const bandwidthLoading = ref(true);
const bandwidthError = ref(false);
const chartSeries = computed(() => { ... });
async function fetchBandwidth() { ... }
let bandwidthPoll = null;
setInterval(fetchBandwidth, 30000);
```

**Files:**
- Create: `resources/js/composables/useBandwidthChart.js`
- Create: `resources/js/composables/__tests__/useBandwidthChart.test.js`
- Modify: `resources/js/Pages/Admin/Users/Show.vue`
- Modify: `resources/js/Pages/Admin/Ips/Show.vue`
- Modify: `resources/js/Pages/Admin/Dashboard.vue`

- [ ] **Step 1.1: Write the failing test**

Create `resources/js/composables/__tests__/useBandwidthChart.test.js`:

```js
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, nextTick } from 'vue';
import { useBandwidthChart } from '../useBandwidthChart.js';

const mockData = {
    timestamps: ['1000', '2000'],
    download: [100, 200],
    upload: [50, 75],
    totalReceived: 1024,
    totalSent: 512,
};

function createWrapper(endpoint = '/api/bandwidth', defaultRange = '24h', pollInterval = 30000) {
    const TestComponent = defineComponent({
        setup() {
            return useBandwidthChart(endpoint, defaultRange, pollInterval);
        },
        template: '<div />',
    });
    return mount(TestComponent);
}

describe('useBandwidthChart', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(mockData),
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('starts with default range and loading state', async () => {
        const wrapper = createWrapper('/api/bandwidth', '24h');
        const { selectedRange, bandwidthLoading } = wrapper.vm;
        expect(selectedRange).toBe('24h');
        expect(bandwidthLoading).toBe(true);
    });

    it('fetches bandwidth data on mount', async () => {
        createWrapper('/api/bandwidth');
        await flushPromises();
        expect(global.fetch).toHaveBeenCalledWith('/api/bandwidth?range=24h');
    });

    it('updates bandwidthData on successful fetch', async () => {
        const wrapper = createWrapper('/api/bandwidth');
        await flushPromises();
        expect(wrapper.vm.bandwidthData.totalReceived).toBe(1024);
        expect(wrapper.vm.bandwidthLoading).toBe(false);
    });

    it('sets bandwidthError on fetch failure', async () => {
        global.fetch = vi.fn().mockRejectedValue(new Error('Network error'));
        const wrapper = createWrapper('/api/bandwidth');
        await flushPromises();
        expect(wrapper.vm.bandwidthError).toBe(true);
        expect(wrapper.vm.bandwidthLoading).toBe(false);
    });

    it('polls at specified interval', async () => {
        createWrapper('/api/bandwidth', '24h', 5000);
        await flushPromises();
        expect(global.fetch).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(5000);
        await flushPromises();
        expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('returns chart series in correct format', async () => {
        const wrapper = createWrapper('/api/bandwidth');
        await flushPromises();
        const series = wrapper.vm.chartSeries;
        expect(series).toHaveLength(2);
        expect(series[0].label).toBe('Download');
        expect(series[1].label).toBe('Upload');
        expect(series[0].data[0]).toMatchObject({ timestamp: 1000, value: 100 });
    });

    it('selectRange updates range and re-fetches', async () => {
        const wrapper = createWrapper('/api/bandwidth');
        await flushPromises();
        wrapper.vm.selectRange('1h');
        expect(wrapper.vm.selectedRange).toBe('1h');
        await flushPromises();
        expect(global.fetch).toHaveBeenLastCalledWith('/api/bandwidth?range=1h');
    });
});
```

- [ ] **Step 1.2: Run to verify it fails**

```bash
npm test -- --run resources/js/composables/__tests__/useBandwidthChart.test.js
```

Expected: FAIL — `useBandwidthChart` not found.

- [ ] **Step 1.3: Create useBandwidthChart.js**

Create `resources/js/composables/useBandwidthChart.js`:

```js
import { ref, computed, onMounted, onUnmounted } from 'vue';

export function useBandwidthChart(endpoint, defaultRange = '24h', pollInterval = 30000) {
    const selectedRange = ref(defaultRange);
    const bandwidthData = ref({
        timestamps: [],
        download: [],
        upload: [],
        totalReceived: 0,
        totalSent: 0,
    });
    const bandwidthLoading = ref(true);
    const bandwidthError = ref(false);

    const chartSeries = computed(() => {
        const { timestamps, download, upload } = bandwidthData.value;
        return [
            {
                label: 'Download',
                color: 'var(--color-success)',
                fill: true,
                data: timestamps.map((ts, i) => ({ timestamp: Number(ts), value: download[i] ?? 0 })),
            },
            {
                label: 'Upload',
                color: 'var(--color-info)',
                fill: true,
                data: timestamps.map((ts, i) => ({ timestamp: Number(ts), value: upload[i] ?? 0 })),
            },
        ];
    });

    async function fetchBandwidth() {
        bandwidthLoading.value = true;
        bandwidthError.value = false;
        try {
            const response = await fetch(`${endpoint}?range=${selectedRange.value}`);
            if (response.ok) {
                bandwidthData.value = await response.json();
            } else {
                bandwidthError.value = true;
            }
        } catch (_e) {
            bandwidthError.value = true;
        } finally {
            bandwidthLoading.value = false;
        }
    }

    function selectRange(range) {
        selectedRange.value = range;
        fetchBandwidth();
    }

    let pollTimer = null;

    onMounted(() => {
        fetchBandwidth();
        pollTimer = setInterval(fetchBandwidth, pollInterval);
    });

    onUnmounted(() => {
        if (pollTimer !== null) {
            clearInterval(pollTimer);
        }
    });

    return {
        selectedRange,
        bandwidthData,
        bandwidthLoading,
        bandwidthError,
        chartSeries,
        selectRange,
        fetchBandwidth,
    };
}
```

- [ ] **Step 1.4: Run tests**

```bash
npm test -- --run resources/js/composables/__tests__/useBandwidthChart.test.js
```

Expected: PASS (8 tests).

- [ ] **Step 1.5: Update Users/Show.vue**

In `resources/js/Pages/Admin/Users/Show.vue`, replace lines 178–232 (the duplicated bandwidth logic) with:

```js
import { useBandwidthChart } from '@/composables/useBandwidthChart.js';

// In setup():
const bandwidthEndpoint = route('admin.users.bandwidth', props.user.id);
const { selectedRange, bandwidthData, bandwidthLoading, bandwidthError, chartSeries, selectRange } =
    useBandwidthChart(bandwidthEndpoint, '24h', 30000);
```

Remove the now-redundant local declarations of `selectedRange`, `bandwidthData`, `bandwidthLoading`, `bandwidthError`, `chartSeries`, `fetchBandwidth`, `selectRange`, the `onMounted` bandwidth block, and the `onUnmounted` poll clear (composable handles lifecycle).

- [ ] **Step 1.6: Update Ips/Show.vue**

In `resources/js/Pages/Admin/Ips/Show.vue`, apply the same replacement:

```js
import { useBandwidthChart } from '@/composables/useBandwidthChart.js';

const bandwidthEndpoint = route('admin.ips.bandwidth', props.ip.address);
const { selectedRange, bandwidthData, bandwidthLoading, bandwidthError, chartSeries, selectRange } =
    useBandwidthChart(bandwidthEndpoint, '24h', 30000);
```

- [ ] **Step 1.7: Update Dashboard.vue**

Dashboard uses range `'1h'` as default and includes `_t=Date.now()` cache-buster. Extract endpoint to a computed:

```js
import { useBandwidthChart } from '@/composables/useBandwidthChart.js';

// Dashboard uses '1h' default and its own polling logic via useAdminChannel
// Pass endpoint without _t — the composable handles polling
const bandwidthEndpoint = route('admin.dashboard.bandwidth');
const { selectedRange, bandwidthData, bandwidthLoading, bandwidthError, chartSeries, selectRange, fetchBandwidth } =
    useBandwidthChart(bandwidthEndpoint, '1h', 30000);
```

Note: Dashboard also calls `fetchBandwidth` in WebSocket event handlers. The composable exposes `fetchBandwidth` for this purpose.

- [ ] **Step 1.8: Run full test suite**

```bash
npm test
npm run lint
```

Expected: All pass.

- [ ] **Step 1.9: Commit**

```bash
git add resources/js/composables/useBandwidthChart.js resources/js/composables/__tests__/useBandwidthChart.test.js resources/js/Pages/Admin/Users/Show.vue resources/js/Pages/Admin/Ips/Show.vue resources/js/Pages/Admin/Dashboard.vue
git commit -m "refactor: extract useBandwidthChart composable

Eliminates ~55 lines of duplicated bandwidth fetch/poll/chart logic from
Users/Show, Ips/Show, and Dashboard. Single composable accepts endpoint,
defaultRange, and pollInterval.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 2: Standardise frontend HTTP layer with useApi()

**Problem:** Three different HTTP patterns coexist:
1. `window.axios` — configured globally in `bootstrap.js` with CSRF, used implicitly
2. Raw `fetch()` — used in Editor.vue, IntegrationShow.vue, Account/Settings.vue, Dashboard.vue etc. without consistent CSRF/error handling
3. Inertia `useForm`/`router` — correct for Inertia-managed requests

The raw `fetch` calls manually add `X-CSRF-Token` inconsistently or rely on cookies. Standardise on axios for non-Inertia JSON requests.

**Files:**
- Create: `resources/js/composables/useApi.js`
- Create: `resources/js/composables/__tests__/useApi.test.js`
- Modify: `resources/js/Pages/Admin/Settings/IntegrationShow.vue` (fetch → useApi)
- Modify: `resources/js/Pages/Admin/Content/Editor.vue` (fetch → useApi)

Note: `Account/Settings.vue` uses WebAuthn endpoints that require specific fetch behaviour (binary data). Do NOT migrate those — leave as raw fetch.

- [ ] **Step 2.1: Write failing test**

Create `resources/js/composables/__tests__/useApi.test.js`:

```js
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useApi } from '../useApi.js';

describe('useApi', () => {
    beforeEach(() => {
        window.axios = {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
        };
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('get() delegates to axios.get with correct URL', async () => {
        window.axios.get.mockResolvedValue({ data: { ok: true } });
        const { get } = useApi();
        const result = await get('/api/test');
        expect(window.axios.get).toHaveBeenCalledWith('/api/test', expect.any(Object));
        expect(result).toEqual({ ok: true });
    });

    it('post() delegates to axios.post with data', async () => {
        window.axios.post.mockResolvedValue({ data: { id: 1 } });
        const { post } = useApi();
        const result = await post('/api/items', { name: 'Test' });
        expect(window.axios.post).toHaveBeenCalledWith('/api/items', { name: 'Test' }, expect.any(Object));
        expect(result).toEqual({ id: 1 });
    });

    it('exposes loading and error state', async () => {
        let resolve;
        window.axios.get.mockReturnValue(new Promise((r) => (resolve = r)));
        const { get, loading, error } = useApi();

        expect(loading.value).toBe(false);
        const promise = get('/api/test');
        expect(loading.value).toBe(true);
        resolve({ data: { ok: true } });
        await promise;
        expect(loading.value).toBe(false);
        expect(error.value).toBeNull();
    });
});
```

- [ ] **Step 2.2: Run to verify it fails**

```bash
npm test -- --run resources/js/composables/__tests__/useApi.test.js
```

Expected: FAIL — `useApi` not found.

- [ ] **Step 2.3: Create useApi.js**

Create `resources/js/composables/useApi.js`:

```js
import { ref } from 'vue';

export function useApi() {
    const loading = ref(false);
    const error = ref(null);

    async function request(method, url, data = null, config = {}) {
        loading.value = true;
        error.value = null;
        try {
            const response = data !== null
                ? await window.axios[method](url, data, config)
                : await window.axios[method](url, config);
            return response.data;
        } catch (err) {
            error.value = err?.response?.data?.message ?? err?.message ?? 'Request failed';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    return {
        loading,
        error,
        get: (url, config = {}) => request('get', url, null, config),
        post: (url, data = {}, config = {}) => request('post', url, data, config),
        put: (url, data = {}, config = {}) => request('put', url, data, config),
        patch: (url, data = {}, config = {}) => request('patch', url, data, config),
        delete: (url, config = {}) => request('delete', url, null, config),
    };
}
```

- [ ] **Step 2.4: Run tests**

```bash
npm test -- --run resources/js/composables/__tests__/useApi.test.js
```

Expected: PASS.

- [ ] **Step 2.5: Migrate IntegrationShow.vue fetch calls**

In `resources/js/Pages/Admin/Settings/IntegrationShow.vue`, replace raw `fetch()` calls with `useApi()`:

```js
import { useApi } from '@/composables/useApi.js';

const { post, loading: apiLoading, error: apiError } = useApi();

// Replace fetch call (line ~83):
// Before:
// const response = await fetch(route(...), { method: 'POST', headers: {...}, body: '{}' });

// After:
const data = await post(route('admin.settings.test', { service: props.service.id }));
```

Read the full IntegrationShow.vue fetch calls first to adapt each one. The `capabilities.update` call should use `post()` as well.

- [ ] **Step 2.6: Migrate Editor.vue fetch calls**

In `resources/js/Pages/Admin/Content/Editor.vue`, replace the `fetch()` calls with `useApi()`. Read the file around lines 200–295 first to understand each fetch call's purpose, then replace:

```js
import { useApi } from '@/composables/useApi.js';

const { post, put, delete: del } = useApi();

// Content save (was fetch with POST):
await post(route('admin.content.store'), data);

// Content update (was fetch with PUT):
await put(`/admin/content/${data.id}`, data);

// Content delete (was fetch with DELETE):
await del(`/admin/content/${id}`);
```

- [ ] **Step 2.7: Run full test suite**

```bash
npm test
npm run lint
```

Expected: All pass.

- [ ] **Step 2.8: Commit**

```bash
git add resources/js/composables/useApi.js resources/js/composables/__tests__/useApi.test.js resources/js/Pages/Admin/Settings/IntegrationShow.vue resources/js/Pages/Admin/Content/Editor.vue
git commit -m "refactor: standardise non-Inertia HTTP calls with useApi composable

useApi() wraps window.axios (pre-configured with CSRF in bootstrap.js)
and provides consistent loading/error state. Migrates IntegrationShow and
Editor from raw fetch() calls.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Final verification

- [ ] **Run complete frontend test suite**

```bash
npm test
```

- [ ] **Run linters**

```bash
npm run lint
npm run format:check 2>/dev/null || true
```

- [ ] **Check git log**

```bash
git log --oneline -10
```
