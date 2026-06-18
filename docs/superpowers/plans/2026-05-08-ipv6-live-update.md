# IPv6 Live Update Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the dashboard IPv6 detection composable successfully detects an IPv6 address, update the connection strip's IPv6 and status fields live without a page refresh.

**Architecture:** Modify `detectAndSubmitIpv6` to return the parsed `POST /ipv6` response. Add an `onDetected` callback to `useIpv6Detection`. Wire the callback in `Dashboard.vue` to update the reactive `liveContext`.

**Tech Stack:** Vue 3, Vitest

---

### File Map

- Modify: `resources/js/composables/useIpv6Detection.js`
- Modify: `resources/js/Pages/Portal/Dashboard.vue`
- Modify: `tests/js/composables/useIpv6Detection.spec.js`

---

### Task 1: Add tests for `detectAndSubmitIpv6` returning response data

**Files:**
- Modify: `tests/js/composables/useIpv6Detection.spec.js`

- [ ] **Step 1: Add test — `detectAndSubmitIpv6` returns `{ ip, internetEnabled }` on success**

Add this test inside the existing `describe('useIpv6Detection', ...)` block, after the "submits token" test (line 63):

```javascript
    it('calls onDetected with ip and internetEnabled from POST response', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ token: 'jwt-token' }) });
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve({ ip: '2001:db8::1', internetEnabled: true }),
        });

        const onDetected = vi.fn();
        useIpv6Detection('https://{uuid}.ipv6.example.com', { onDetected });

        await vi.advanceTimersByTimeAsync(0);

        expect(onDetected).toHaveBeenCalledWith({ ip: '2001:db8::1', internetEnabled: true });
    });
```

- [ ] **Step 2: Add test — `onDetected` is not called when detection fails**

```javascript
    it('does not call onDetected when detection response is not ok', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false });

        const onDetected = vi.fn();
        useIpv6Detection('https://{uuid}.ipv6.example.com', { onDetected });

        await vi.advanceTimersByTimeAsync(0);

        expect(onDetected).not.toHaveBeenCalled();
    });
```

- [ ] **Step 3: Add test — `onDetected` is not called when POST response is not ok**

```javascript
    it('does not call onDetected when POST response is not ok', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ token: 'jwt' }) });
        fetchMock.mockResolvedValueOnce({ ok: false });

        const onDetected = vi.fn();
        useIpv6Detection('https://{uuid}.ipv6.example.com', { onDetected });

        await vi.advanceTimersByTimeAsync(0);

        expect(onDetected).not.toHaveBeenCalled();
    });
```

- [ ] **Step 4: Add test — works without `onDetected` (backward compat)**

```javascript
    it('works without onDetected callback', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ token: 'jwt' }) });
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ ip: '::1', internetEnabled: true }) });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await expect(vi.advanceTimersByTimeAsync(0)).resolves.not.toThrow();
    });
```

- [ ] **Step 5: Update existing interval test to use options object**

Change line 98 from:

```javascript
        useIpv6Detection('https://{uuid}.ipv6.example.com', 5000);
```

to:

```javascript
        useIpv6Detection('https://{uuid}.ipv6.example.com', { intervalMs: 5000 });
```

- [ ] **Step 6: Run tests to verify they fail**

Run: `npx vitest run tests/js/composables/useIpv6Detection.spec.js`
Expected: New tests FAIL (onDetected not yet implemented), existing interval test FAILS (signature changed).

- [ ] **Step 7: Commit failing tests**

```bash
git add tests/js/composables/useIpv6Detection.spec.js
git commit -m "test: add failing tests for IPv6 detection onDetected callback"
```

---

### Task 2: Implement `detectAndSubmitIpv6` return value and `onDetected` callback

**Files:**
- Modify: `resources/js/composables/useIpv6Detection.js`

- [ ] **Step 1: Modify `detectAndSubmitIpv6` to return parsed response**

Replace the entire `detectAndSubmitIpv6` function (lines 13-28):

```javascript
async function detectAndSubmitIpv6(endpointTemplate) {
    const endpoint = endpointTemplate.replace('{uuid}', generateUuid());
    try {
        const response = await fetch(endpoint);
        if (!response.ok) return null;
        const data = await response.json();
        if (!data.token || !data.token.trim()) return null;
        const postResponse = await fetch('/ipv6', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: data.token.trim() }),
        });
        if (!postResponse.ok) return null;
        return await postResponse.json();
    } catch {
        return null;
    }
}
```

- [ ] **Step 2: Modify `useIpv6Detection` to accept options object and call `onDetected`**

Replace the entire `useIpv6Detection` function (lines 30-51):

```javascript
export function useIpv6Detection(endpointTemplate, options = {}) {
    const { onDetected, intervalMs = 120000 } = options;
    let timer = null;

    async function runDetection() {
        const result = await detectAndSubmitIpv6(endpointTemplate);
        if (result && onDetected) {
            onDetected(result);
        }
    }

    onMounted(() => {
        if (!endpointTemplate) return;

        runDetection();

        timer = setInterval(runDetection, intervalMs);
    });

    onUnmounted(() => {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    });

    return { detectAndSubmitIpv6 };
}
```

- [ ] **Step 3: Run tests to verify they pass**

Run: `npx vitest run tests/js/composables/useIpv6Detection.spec.js`
Expected: ALL tests PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/composables/useIpv6Detection.js
git commit -m "feat: return IPv6 response and add onDetected callback to useIpv6Detection"
```

---

### Task 3: Wire `onDetected` in Dashboard.vue

**Files:**
- Modify: `resources/js/Pages/Portal/Dashboard.vue`

- [ ] **Step 1: Replace the `useIpv6Detection` call to pass `onDetected`**

Change line 36 from:

```javascript
useIpv6Detection(props.ipv6Detection?.endpoint);
```

to:

```javascript
useIpv6Detection(props.ipv6Detection?.endpoint, {
    onDetected(data) {
        liveContext.currentIpv6 = data.ip;
        liveContext.internetEnabled = data.internetEnabled;
    },
});
```

- [ ] **Step 2: Run full JS test suite**

Run: `npx vitest run`
Expected: ALL tests PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Portal/Dashboard.vue
git commit -m "feat: update dashboard IPv6 and status fields on live detection"
```

---

### Task 4: Formatting and lint

**Files:**
- All modified files

- [ ] **Step 1: Run prettier**

Run: `npx prettier --write resources/js/composables/useIpv6Detection.js resources/js/Pages/Portal/Dashboard.vue tests/js/composables/useIpv6Detection.spec.js`

- [ ] **Step 2: Run eslint**

Run: `npx eslint resources/js/composables/useIpv6Detection.js resources/js/Pages/Portal/Dashboard.vue tests/js/composables/useIpv6Detection.spec.js`
Expected: No errors.

- [ ] **Step 3: Run full test suite again**

Run: `npx vitest run`
Expected: ALL tests PASS.

- [ ] **Step 4: Commit if any formatting changes**

```bash
git add -A
git commit -m "style: format IPv6 live update files"
```
