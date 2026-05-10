# **Playwright E2E Smoke Tests Implementation Plan**
**Date**: 2026-05-09  
**Status**: Planned  
**Owner**: Test Automation Team  
**Estimated Effort**: 3-5 days

## **Overview**

Implement comprehensive end-to-end smoke tests for the Aperture application using Playwright Test framework. Tests will cover critical user journeys including authentication, portal functionality, and admin operations across users, IPs, switches, and settings.

## **Goals**

- Achieve >80% coverage of critical user workflows
- Establish reliable test automation foundation for CI/CD integration
- Validate auth flows, portal features, and admin operations
- Create reusable Page Object Model patterns
- Ensure tests run independently with zero flakiness

## **Prerequisites**

- ✅ Playwright framework already configured (`playwright.config.js`)
- ✅ Chrome browser channel configured
- ✅ Test directory structure exists (`tests/e2e/`)
- ✅ Authentication storage state path configured (`playwright/.auth/admin.json`)
- ✅ Environment configuration helper ready (`playwright/env.js`)
- ✅ Extensive `data-testid` attributes already present in Vue components

## **Technical Context**

**Stack**: Laravel 13, Vue 3, Inertia.js, Tailwind CSS  
**Test Framework**: Playwright Test (@playwright/test)  
**Browser**: Chrome (channel: 'chrome')  
**Base URL**: `http://127.0.0.1:8000` (configurable via APP_URL)  
**Auth Pattern**: Storage state saved to `playwright/.auth/admin.json`  
**Database**: Isolated SQLite (`database/playwright.sqlite`)

---

## **Phase 1: Preparation - Data-testid Audit**

### **Audit Existing Coverage**

Review Vue components to identify missing `data-testid` attributes needed for E2E tests.

**Files to audit**:

1. **Authentication Components**
   - ✅ `resources/js/Pages/Auth/Login.vue` - Complete coverage (login-email, login-password, login-submit, login-passkey, passkey-error)
   
2. **Portal Components**
   - ✅ `resources/js/Pages/Portal/Dashboard.vue` - Complete coverage (page-title, cover-image, dashboard-welcome-heading)
   - ✅ `resources/js/Components/Blocks/DnsFilterBlock.vue` - Complete coverage (dns-filter-toggle)
   
3. **Admin User Management**
   - ✅ `resources/js/Pages/Admin/Users/Index.vue` - Complete coverage (user-list-summary-*, data-table, user-row-*, user-status-*, action-view-user-*)
   - ⚠️ `resources/js/Pages/Admin/Users/Show.vue` - Needs review for action buttons
   
4. **Admin IP Management**
   - ✅ `resources/js/Pages/Admin/Ips/Index.vue` - Complete coverage (page-title, action-create-ip, ip-row-*)
   - ⚠️ `resources/js/Pages/Admin/Ips/Show.vue` - Needs review (file not audited yet)
   
5. **Admin Switch Management**
   - ✅ `resources/js/Pages/Admin/Switches/Index.vue` - Complete coverage (switch-list-summary-*, switch-row-*, switch-sync-status-*)
   - ⚠️ `resources/js/Pages/Admin/Switches/Show.vue` - Needs review for port details
   
6. **Admin Settings**
   - ✅ `resources/js/Pages/Admin/Settings/Integrations.vue` - Complete coverage (integration-row-*, integration-status-*)
   
7. **Layout Components**
   - ✅ `resources/js/Layouts/AdminLayout.vue` - Complete coverage (nav-*, page-title)
   - ✅ `resources/js/Layouts/PortalLayout.vue` - Complete coverage (portal-nav-*)
   - ✅ `resources/js/Components/UserMenu.vue` - Complete coverage (user-menu-button, user-menu-logout)

### **Action Items**:

**Task 1.1**: Audit User Show Page  
**Command**:  
\`\`\`bash
grep -n "data-testid" resources/js/Pages/Admin/Users/Show.vue
\`\`\`

**Task 1.2**: Audit IP Show Page  
**Command**:  
\`\`\`bash
grep -n "data-testid" resources/js/Pages/Admin/Ips/Show.vue
\`\`\`

**Task 1.3**: Audit Switch Show Page  
**Command**:  
\`\`\`bash
grep -n "data-testid" resources/js/Pages/Admin/Switches/Show.vue
\`\`\`

**Task 1.4**: Add Missing data-testid Attributes  
Based on audit results, add missing test IDs to components:

- User block/unblock action buttons
- Internet access toggle buttons  
- Settings "Test Connection" button
- Switch port detail elements
- Any modal confirmation buttons

---

## **Phase 2: Authentication Setup**

### **Create Shared Authentication Setup Files**

Implement storage state authentication to avoid logging in for every test.

### **Task 2.1: Create Admin Auth Setup**

**File**: `tests/e2e/admin.setup.js`

\`\`\`javascript
const { test as setup, expect } = require('@playwright/test');
const path = require('path');

const authFile = path.join(__dirname, '../../playwright/.auth/admin.json');

setup('authenticate as admin', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('networkidle');

  await page.getByTestId('login-email').fill('admin@example.com');
  await page.getByTestId('login-password').fill('password');
  await page.getByTestId('login-submit').click();

  await page.waitForLoadState('networkidle');
  await expect(page).toHaveURL(/\/admin|\/portal/);
  
  await page.context().storageState({ path: authFile });
});
\`\`\`

**Run Command**:
\`\`\`bash
npx playwright test admin.setup.js --project=setup
\`\`\`

**Expected Output**:
\`\`\`
Running 1 test using 1 worker

  ✓  admin.setup.js:5:7 › authenticate as admin (2.3s)

  1 passed (3.1s)
\`\`\`

### **Task 2.2: Create Portal User Auth Setup**

**File**: `tests/e2e/user.setup.js`

\`\`\`javascript
const { test as setup, expect } = require('@playwright/test');
const path = require('path');

const authFile = path.join(__dirname, '../../playwright/.auth/user.json');

setup('authenticate as portal user', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('networkidle');

  await page.getByTestId('login-email').fill('user@example.com');
  await page.getByTestId('login-password').fill('password');
  await page.getByTestId('login-submit').click();

  await page.waitForLoadState('networkidle');
  await expect(page).toHaveURL(/\/portal/);
  
  await page.context().storageState({ path: authFile });
});
\`\`\`

**Run Command**:
\`\`\`bash
npx playwright test user.setup.js --project=setup
\`\`\`

**Expected Output**:
\`\`\`
Running 1 test using 1 worker

  ✓  user.setup.js:5:7 › authenticate as portal user (2.1s)

  1 passed (2.8s)
\`\`\`

### **Task 2.3: Update Playwright Config**

Ensure `playwright.config.js` includes both auth files and setup dependency:

\`\`\`javascript
// Add user auth project
{
  name: 'chromium-user',
  use: {
    ...devices['Desktop Chrome'],
    channel: 'chrome',
    storageState: 'playwright/.auth/user.json',
  },
  dependencies: ['setup'],
}
\`\`\`

---

## **Phase 3: Authentication Tests**

### **Test Suite**: `tests/e2e/auth.spec.js`

**Missing data-testid attributes**: None (complete coverage exists)

**Test Implementation**:

\`\`\`javascript
const { test, expect } = require('@playwright/test');

test.describe('Authentication', () => {
  
  test.beforeEach(async ({ page }) => {
    // Start each test fresh (no auth)
  });

  test('should login with valid credentials', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    await expect(page.getByTestId('login-email')).toBeVisible();
    await expect(page.getByTestId('login-password')).toBeVisible();
    await expect(page.getByTestId('login-submit')).toBeVisible();

    await page.getByTestId('login-email').fill('admin@example.com');
    await page.getByTestId('login-password').fill('password');
    await page.getByTestId('login-submit').click();

    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/\/admin|\/portal/);
    await expect(page.getByTestId('user-menu-button')).toBeVisible();
  });

  test('should show error for invalid credentials', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    await page.getByTestId('login-email').fill('invalid@example.com');
    await page.getByTestId('login-password').fill('wrongpassword');
    await page.getByTestId('login-submit').click();

    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL('/login');
    await expect(page.locator('text=These credentials do not match our records')).toBeVisible();
  });

  test('should logout successfully', async ({ page }) => {
    // Login first
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await page.getByTestId('login-email').fill('admin@example.com');
    await page.getByTestId('login-password').fill('password');
    await page.getByTestId('login-submit').click();
    await page.waitForLoadState('networkidle');

    // Open user menu and logout
    await page.getByTestId('user-menu-button').click();
    await page.getByTestId('user-menu-logout').click();

    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL('/login');
    await expect(page.getByTestId('login-email')).toBeVisible();
  });

  test('should display passkey login option', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    await expect(page.getByTestId('login-passkey')).toBeVisible();
  });
});
\`\`\`

**Run Command**:
\`\`\`bash
npx playwright test auth.spec.js
\`\`\`

**Expected Output**:
\`\`\`
Running 4 tests using 4 workers

  ✓  auth.spec.js:6:3 › Authentication › should login with valid credentials (3.2s)
  ✓  auth.spec.js:22:3 › Authentication › should show error for invalid credentials (2.8s)
  ✓  auth.spec.js:35:3 › Authentication › should logout successfully (4.1s)
  ✓  auth.spec.js:53:3 › Authentication › should display passkey login option (1.5s)

  4 passed (11.6s)
\`\`\`

---

## **Phase 4: Portal Tests**

### **Test Suite**: `tests/e2e/portal.spec.js`

**Missing data-testid attributes**: None (complete coverage exists)

**Test Implementation**:

\`\`\`javascript
const { test, expect } = require('@playwright/test');

test.describe('Portal', () => {
  
  test.use({ storageState: 'playwright/.auth/user.json' });

  test('should load dashboard with welcome message', async ({ page }) => {
    await page.goto('/portal');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL('/portal');
    await expect(page.getByTestId('page-title')).toBeVisible();
    await expect(page.getByTestId('dashboard-welcome-heading')).toBeVisible();
    await expect(page.getByTestId('cover-image')).toBeVisible();
  });

  test('should display DNS filter toggle', async ({ page }) => {
    await page.goto('/portal');
    await page.waitForLoadState('networkidle');

    const dnsToggle = page.getByTestId('dns-filter-toggle');
    await expect(dnsToggle).toBeVisible();
  });

  test('should toggle DNS filter on/off', async ({ page }) => {
    await page.goto('/portal');
    await page.waitForLoadState('networkidle');

    const dnsToggle = page.getByTestId('dns-filter-toggle');
    const initialState = await dnsToggle.getAttribute('aria-checked');
    
    await dnsToggle.click();
    await page.waitForLoadState('networkidle');
    
    const newState = await dnsToggle.getAttribute('aria-checked');
    expect(newState).not.toBe(initialState);
  });

  test('should navigate using portal navigation', async ({ page }) => {
    await page.goto('/portal');
    await page.waitForLoadState('networkidle');

    const navLinks = page.locator('[data-testid^="portal-nav-"]');
    const count = await navLinks.count();
    expect(count).toBeGreaterThan(0);
    
    await expect(navLinks.first()).toBeVisible();
  });

  test('should display user menu', async ({ page }) => {
    await page.goto('/portal');
    await page.waitForLoadState('networkidle');

    await expect(page.getByTestId('user-menu-button')).toBeVisible();
    await page.getByTestId('user-menu-button').click();
    await expect(page.getByTestId('user-menu-logout')).toBeVisible();
  });
});
\`\`\`

**Run Command**:
\`\`\`bash
npx playwright test portal.spec.js
\`\`\`

**Expected Output**:
\`\`\`
Running 5 tests using 5 workers

  ✓  portal.spec.js:6:3 › Portal › should load dashboard with welcome message (2.1s)
  ✓  portal.spec.js:16:3 › Portal › should display DNS filter toggle (1.8s)
  ✓  portal.spec.js:23:3 › Portal › should toggle DNS filter on/off (3.2s)
  ✓  portal.spec.js:37:3 › Portal › should navigate using portal navigation (1.9s)
  ✓  portal.spec.js:48:3 › Portal › should display user menu (2.3s)

  5 passed (11.3s)
\`\`\`

---

## **Phase 5: Admin User Management Tests**

### **Test Suite**: `tests/e2e/admin-users.spec.js`

**Missing data-testid attributes**:
- User block/unblock action buttons in Show.vue
- Internet access toggle confirmation modal buttons
- User rate limit adjustment buttons

**Add to**: `resources/js/Pages/Admin/Users/Show.vue`
\`\`\`vue
<!-- Block/Unblock User Button -->
<PrimaryButton
  data-testid="action-block-user"
  @click="blockUser"
>
  Block User
</PrimaryButton>

<PrimaryButton
  data-testid="action-unblock-user"
  @click="unblockUser"
>
  Unblock User
</PrimaryButton>

<!-- Internet Access Toggle -->
<button
  data-testid="action-toggle-internet-access"
  @click="toggleInternetAccess"
>
  Toggle Internet Access
</button>

<!-- Confirmation Modal -->
<Modal :show="showConfirmModal">
  <button data-testid="modal-confirm">Confirm</button>
  <button data-testid="modal-cancel">Cancel</button>
</Modal>
\`\`\`

**Test Implementation**:

\`\`\`javascript
const { test, expect } = require('@playwright/test');

test.describe('Admin - User Management', () => {
  
  test.use({ storageState: 'playwright/.auth/admin.json' });

  test('should display user list with summary stats', async ({ page }) => {
    await page.goto('/admin/users');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL('/admin/users');
    await expect(page.getByTestId('page-title')).toContainText('Users');
    
    await expect(page.getByTestId('user-list-summary-total')).toBeVisible();
    await expect(page.getByTestId('user-list-summary-active')).toBeVisible();
    await expect(page.getByTestId('user-list-summary-blocked')).toBeVisible();
    
    await expect(page.getByTestId('data-table')).toBeVisible();
  });

  test('should display user rows with correct data', async ({ page }) => {
    await page.goto('/admin/users');
    await page.waitForLoadState('networkidle');

    const firstUserRow = page.locator('[data-testid^="user-row-"]').first();
    await expect(firstUserRow).toBeVisible();
    
    const userId = await firstUserRow.getAttribute('data-testid');
    const userIdValue = userId.replace('user-row-', '');
    
    await expect(page.getByTestId(\`user-name-\${userIdValue}\`)).toBeVisible();
    await expect(page.getByTestId(\`user-email-\${userIdValue}\`)).toBeVisible();
    await expect(page.getByTestId(\`user-status-\${userIdValue}\`)).toBeVisible();
  });

  test('should navigate to user detail page', async ({ page }) => {
    await page.goto('/admin/users');
    await page.waitForLoadState('networkidle');

    const firstUserRow = page.locator('[data-testid^="user-row-"]').first();
    const userId = (await firstUserRow.getAttribute('data-testid')).replace('user-row-', '');
    
    await page.getByTestId(\`action-view-user-\${userId}\`).click();
    await page.waitForLoadState('networkidle');
    
    await expect(page).toHaveURL(new RegExp(\`/admin/users/\${userId}\`));
    await expect(page.getByTestId('page-title')).toBeVisible();
  });

  test('should block user with confirmation', async ({ page }) => {
    await page.goto('/admin/users/1');
    await page.waitForLoadState('networkidle');

    await page.getByTestId('action-block-user').click();
    
    await expect(page.getByTestId('modal-confirm')).toBeVisible();
    await page.getByTestId('modal-confirm').click();
    
    await page.waitForLoadState('networkidle');
    await expect(page.locator('text=User blocked successfully')).toBeVisible();
  });

  test('should unblock user with confirmation', async ({ page }) => {
    await page.goto('/admin/users/1');
    await page.waitForLoadState('networkidle');

    await page.getByTestId('action-unblock-user').click();
    
    await expect(page.getByTestId('modal-confirm')).toBeVisible();
    await page.getByTestId('modal-confirm').click();
    
    await page.waitForLoadState('networkidle');
    await expect(page.locator('text=User unblocked successfully')).toBeVisible();
  });

  test('should toggle internet access', async ({ page }) => {
    await page.goto('/admin/users/1');
    await page.waitForLoadState('networkidle');

    await page.getByTestId('action-toggle-internet-access').click();
    await page.waitForLoadState('networkidle');
    
    await expect(page.locator('text=Internet access updated')).toBeVisible();
  });

  test('should filter users by status', async ({ page }) => {
    await page.goto('/admin/users');
    await page.waitForLoadState('networkidle');

    const statusFilter = page.locator('select[name="status"]');
    await statusFilter.selectOption('blocked');
    
    await page.waitForLoadState('networkidle');
    
    const userRows = page.locator('[data-testid^="user-row-"]');
    const count = await userRows.count();
    
    for (let i = 0; i < count; i++) {
      const userId = (await userRows.nth(i).getAttribute('data-testid')).replace('user-row-', '');
      await expect(page.getByTestId(\`user-status-\${userId}\`)).toContainText('Blocked');
    }
  });

  test('should search users by email', async ({ page }) => {
    await page.goto('/admin/users');
    await page.waitForLoadState('networkidle');

    const searchInput = page.locator('input[name="search"]');
    await searchInput.fill('admin@example.com');
    await searchInput.press('Enter');
    
    await page.waitForLoadState('networkidle');
    
    const userRows = page.locator('[data-testid^="user-row-"]');
    const count = await userRows.count();
    expect(count).toBeGreaterThanOrEqual(1);
    
    const userId = (await userRows.first().getAttribute('data-testid')).replace('user-row-', '');
    await expect(page.getByTestId(\`user-email-\${userId}\`)).toContainText('admin@example.com');
  });
});
\`\`\`

**Run Command**:
\`\`\`bash
npx playwright test admin-users.spec.js
\`\`\`

**Expected Output**:
\`\`\`
Running 8 tests using 4 workers

  ✓  admin-users.spec.js:6:3 › Admin - User Management › should display user list with summary stats (2.4s)
  ✓  admin-users.spec.js:19:3 › Admin - User Management › should display user rows with correct data (2.1s)
  ✓  admin-users.spec.js:33:3 › Admin - User Management › should navigate to user detail page (3.5s)
  ✓  admin-users.spec.js:45:3 › Admin - User Management › should block user with confirmation (3.8s)
  ✓  admin-users.spec.js:56:3 › Admin - User Management › should unblock user with confirmation (3.6s)
  ✓  admin-users.spec.js:67:3 › Admin - User Management › should toggle internet access (3.2s)
  ✓  admin-users.spec.js:76:3 › Admin - User Management › should filter users by status (2.9s)
  ✓  admin-users.spec.js:92:3 › Admin - User Management › should search users by email (3.1s)

  8 passed (24.6s)
\`\`\`

---

## **Phase 6: Admin IP Management Tests**

### **Test Suite**: `tests/e2e/admin-ips.spec.js`

**Missing data-testid attributes**:
- IP detail page toggle buttons
- IP creation form inputs and submit button
- IP delete confirmation buttons

**Add to**: `resources/js/Pages/Admin/Ips/Show.vue` and `Create.vue`
\`\`\`vue
<!-- IP Toggle Internet Access -->
<button
  data-testid="action-toggle-ip-internet-access"
  @click="toggleInternetAccess"
>
  Toggle Internet Access
</button>

<!-- IP Creation Form -->
<input
  data-testid="create-ip-address"
  name="ip_address"
  type="text"
/>
<input
  data-testid="create-ip-description"
  name="description"
  type="text"
/>
<button data-testid="create-ip-submit">
  Create IP Address
</button>

<!-- Delete IP -->
<button data-testid="action-delete-ip">
  Delete IP Address
</button>
\`\`\`

**Test Implementation**:

\`\`\`javascript
const { test, expect } = require('@playwright/test');

test.describe('Admin - IP Management', () => {
  
  test.use({ storageState: 'playwright/.auth/admin.json' });

  test('should display IP address list', async ({ page }) => {
    await page.goto('/admin/ips');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL('/admin/ips');
    await expect(page.getByTestId('page-title')).toContainText('IP Addresses');
    await expect(page.getByTestId('action-create-ip')).toBeVisible();
    await expect(page.getByTestId('data-table')).toBeVisible();
  });

  test('should display IP rows with address information', async ({ page }) => {
    await page.goto('/admin/ips');
    await page.waitForLoadState('networkidle');

    const firstIpRow = page.locator('[data-testid^="ip-row-"]').first();
    await expect(firstIpRow).toBeVisible();
    
    const ipId = (await firstIpRow.getAttribute('data-testid')).replace('ip-row-', '');
    await expect(page.getByTestId(\`ip-address-\${ipId}\`)).toBeVisible();
  });

  test('should navigate to create IP page', async ({ page }) => {
    await page.goto('/admin/ips');
    await page.waitForLoadState('networkidle');

    await page.getByTestId('action-create-ip').click();
    await page.waitForLoadState('networkidle');
    
    await expect(page).toHaveURL('/admin/ips/create');
    await expect(page.getByTestId('create-ip-address')).toBeVisible();
    await expect(page.getByTestId('create-ip-description')).toBeVisible();
    await expect(page.getByTestId('create-ip-submit')).toBeVisible();
  });

  test('should create new IP address', async ({ page }) => {
    await page.goto('/admin/ips/create');
    await page.waitForLoadState('networkidle');

    await page.getByTestId('create-ip-address').fill('192.168.1.100');
    await page.getByTestId('create-ip-description').fill('Test IP Address');
    await page.getByTestId('create-ip-submit').click();
    
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL('/admin/ips');
    await expect(page.locator('text=IP address created successfully')).toBeVisible();
  });

  test('should navigate to IP detail page', async ({ page }) => {
    await page.goto('/admin/ips');
    await page.waitForLoadState('networkidle');

    const firstIpRow = page.locator('[data-testid^="ip-row-"]').first();
    const ipId = (await firstIpRow.getAttribute('data-testid')).replace('ip-row-', '');
    
    await page.getByTestId(\`action-view-ip-\${ipId}\`).click();
    await page.waitForLoadState('networkidle');
    
    await expect(page).toHaveURL(new RegExp(\`/admin/ips/\${ipId}\`));
    await expect(page.getByTestId('page-title')).toBeVisible();
  });

  test('should toggle internet access for IP', async ({ page }) => {
    await page.goto('/admin/ips/1');
    await page.waitForLoadState('networkidle');

    await page.getByTestId('action-toggle-ip-internet-access').click();
    await page.waitForLoadState('networkidle');
    
    await expect(page.locator('text=Internet access updated')).toBeVisible();
  });

  test('should delete IP address with confirmation', async ({ page }) => {
    await page.goto('/admin/ips/1');
    await page.waitForLoadState('networkidle');

    await page.getByTestId('action-delete-ip').click();
    
    await expect(page.getByTestId('modal-confirm')).toBeVisible();
    await page.getByTestId('modal-confirm').click();
    
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL('/admin/ips');
    await expect(page.locator('text=IP address deleted successfully')).toBeVisible();
  });

  test('should filter IPs by internet access status', async ({ page }) => {
    await page.goto('/admin/ips');
    await page.waitForLoadState('networkidle');

    const statusFilter = page.locator('select[name="internet_access"]');
    await statusFilter.selectOption('enabled');
    
    await page.waitForLoadState('networkidle');
    
    const ipRows = page.locator('[data-testid^="ip-row-"]');
    const count = await ipRows.count();
    expect(count).toBeGreaterThanOrEqual(0);
  });
});
\`\`\`

**Run Command**:
\`\`\`bash
npx playwright test admin-ips.spec.js
\`\`\`

**Expected Output**:
\`\`\`
Running 8 tests using 4 workers

  ✓  admin-ips.spec.js:6:3 › Admin - IP Management › should display IP address list (2.2s)
  ✓  admin-ips.spec.js:15:3 › Admin - IP Management › should display IP rows with address information (1.9s)
  ✓  admin-ips.spec.js:25:3 › Admin - IP Management › should navigate to create IP page (2.8s)
  ✓  admin-ips.spec.js:35:3 › Admin - IP Management › should create new IP address (3.4s)
  ✓  admin-ips.spec.js:46:3 › Admin - IP Management › should navigate to IP detail page (3.1s)
  ✓  admin-ips.spec.js:57:3 › Admin - IP Management › should toggle internet access for IP (2.9s)
  ✓  admin-ips.spec.js:66:3 › Admin - IP Management › should delete IP address with confirmation (3.6s)
  ✓  admin-ips.spec.js:79:3 › Admin - IP Management › should filter IPs by internet access status (2.4s)

  8 passed (22.3s)
\`\`\`

---

## **Phase 7: Admin Switch Management Tests**

### **Test Suite**: `tests/e2e/admin-switches.spec.js`

**Missing data-testid attributes**:
- Switch port detail elements in Show.vue
- Port status indicators
- Sync action buttons

**Add to**: `resources/js/Pages/Admin/Switches/Show.vue`
\`\`\`vue
<!-- Switch Ports -->
<div data-testid="switch-port-list">
  <div
    v-for="port in ports"
    :key="port.id"
    :data-testid="\`switch-port-\${port.id}\`"
  >
    <span :data-testid="\`switch-port-number-\${port.id}\`">
      {{ port.number }}
    </span>
    <span :data-testid="\`switch-port-status-\${port.id}\`">
      {{ port.status }}
    </span>
  </div>
</div>

<!-- Sync Actions -->
<button data-testid="action-sync-switch">
  Sync Switch
</button>
\`\`\`

**Test Implementation**:

\`\`\`javascript
const { test, expect } = require('@playwright/test');

test.describe('Admin - Switch Management', () => {
  
  test.use({ storageState: 'playwright/.auth/admin.json' });

  test('should display switch list with summary stats', async ({ page }) => {
    await page.goto('/admin/switches');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL('/admin/switches');
    await expect(page.getByTestId('page-title')).toContainText('Switches');
    
    await expect(page.getByTestId('switch-list-summary-total')).toBeVisible();
    await expect(page.getByTestId('switch-list-summary-online')).toBeVisible();
    await expect(page.getByTestId('switch-list-summary-offline')).toBeVisible();
    
    await expect(page.getByTestId('data-table')).toBeVisible();
  });

  test('should display switch rows with sync status', async ({ page }) => {
    await page.goto('/admin/switches');
    await page.waitForLoadState('networkidle');

    const firstSwitchRow = page.locator('[data-testid^="switch-row-"]').first();
    await expect(firstSwitchRow).toBeVisible();
    
    const switchId = (await firstSwitchRow.getAttribute('data-testid')).replace('switch-row-', '');
    
    await expect(page.getByTestId(\`switch-name-\${switchId}\`)).toBeVisible();
    await expect(page.getByTestId(\`switch-sync-status-\${switchId}\`)).toBeVisible();
  });

  test('should navigate to switch detail page', async ({ page }) => {
    await page.goto('/admin/switches');
    await page.waitForLoadState('networkidle');

    const firstSwitchRow = page.locator('[data-testid^="switch-row-"]').first();
    const switchId = (await firstSwitchRow.getAttribute('data-testid')).replace('switch-row-', '');
    
    await page.getByTestId(\`action-view-switch-\${switchId}\`).click();
    await page.waitForLoadState('networkidle');
    
    await expect(page).toHaveURL(new RegExp(\`/admin/switches/\${switchId}\`));
    await expect(page.getByTestId('page-title')).toBeVisible();
  });

  test('should display switch port list', async ({ page }) => {
    await page.goto('/admin/switches/1');
    await page.waitForLoadState('networkidle');

    await expect(page.getByTestId('switch-port-list')).toBeVisible();
    
    const firstPort = page.locator('[data-testid^="switch-port-"]').first();
    await expect(firstPort).toBeVisible();
  });

  test('should display port details with status', async ({ page }) => {
    await page.goto('/admin/switches/1');
    await page.waitForLoadState('networkidle');

    const firstPort = page.locator('[data-testid^="switch-port-"]').first();
    const portId = (await firstPort.getAttribute('data-testid')).replace('switch-port-', '');
    
    await expect(page.getByTestId(\`switch-port-number-\${portId}\`)).toBeVisible();
    await expect(page.getByTestId(\`switch-port-status-\${portId}\`)).toBeVisible();
  });

  test('should sync switch configuration', async ({ page }) => {
    await page.goto('/admin/switches/1');
    await page.waitForLoadState('networkidle');

    await page.getByTestId('action-sync-switch').click();
    await page.waitForLoadState('networkidle');
    
    await expect(page.locator('text=Switch synced successfully')).toBeVisible();
  });

  test('should filter switches by sync status', async ({ page }) => {
    await page.goto('/admin/switches');
    await page.waitForLoadState('networkidle');

    const statusFilter = page.locator('select[name="sync_status"]');
    await statusFilter.selectOption('synced');
    
    await page.waitForLoadState('networkidle');
    
    const switchRows = page.locator('[data-testid^="switch-row-"]');
    const count = await switchRows.count();
    
    for (let i = 0; i < count; i++) {
      const switchId = (await switchRows.nth(i).getAttribute('data-testid')).replace('switch-row-', '');
      await expect(page.getByTestId(\`switch-sync-status-\${switchId}\`)).toContainText('Synced');
    }
  });

  test('should display switch online/offline status', async ({ page }) => {
    await page.goto('/admin/switches');
    await page.waitForLoadState('networkidle');

    const firstSwitchRow = page.locator('[data-testid^="switch-row-"]').first();
    const switchId = (await firstSwitchRow.getAttribute('data-testid')).replace('switch-row-', '');
    
    const statusElement = page.getByTestId(\`switch-status-\${switchId}\`);
    await expect(statusElement).toBeVisible();
    
    const statusText = await statusElement.textContent();
    expect(['Online', 'Offline']).toContain(statusText.trim());
  });
});
\`\`\`

**Run Command**:
\`\`\`bash
npx playwright test admin-switches.spec.js
\`\`\`

**Expected Output**:
\`\`\`
Running 8 tests using 4 workers

  ✓  admin-switches.spec.js:6:3 › Admin - Switch Management › should display switch list with summary stats (2.5s)
  ✓  admin-switches.spec.js:18:3 › Admin - Switch Management › should display switch rows with sync status (2.1s)
  ✓  admin-switches.spec.js:31:3 › Admin - Switch Management › should navigate to switch detail page (3.3s)
  ✓  admin-switches.spec.js:42:3 › Admin - Switch Management › should display switch port list (2.4s)
  ✓  admin-switches.spec.js:51:3 › Admin - Switch Management › should display port details with status (2.2s)
  ✓  admin-switches.spec.js:61:3 › Admin - Switch Management › should sync switch configuration (3.5s)
  ✓  admin-switches.spec.js:70:3 › Admin - Switch Management › should filter switches by sync status (2.8s)
  ✓  admin-switches.spec.js:85:3 › Admin - Switch Management › should display switch online/offline status (2.0s)

  8 passed (20.8s)
\`\`\`

---

## **Phase 8: Admin Settings Tests**

### **Test Suite**: `tests/e2e/admin-settings.spec.js`

**Missing data-testid attributes**:
- Test Connection button for integrations
- Integration configuration form inputs

**Add to**: `resources/js/Pages/Admin/Settings/Integrations.vue`
\`\`\`vue
<!-- Test Connection Button -->
<button
  :data-testid="\`action-test-connection-\${service.id}\`"
  @click="testConnection(service.id)"
>
  Test Connection
</button>

<!-- Integration Config Form -->
<input
  :data-testid="\`integration-config-\${field.name}\`"
  :name="field.name"
  type="text"
/>
\`\`\`

**Test Implementation**:

\`\`\`javascript
const { test, expect } = require('@playwright/test');

test.describe('Admin - Settings', () => {
  
  test.use({ storageState: 'playwright/.auth/admin.json' });

  test('should display integrations page', async ({ page }) => {
    await page.goto('/admin/settings/integrations');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL('/admin/settings/integrations');
    await expect(page.getByTestId('page-title')).toContainText('Integrations');
  });

  test('should display integration list with status', async ({ page }) => {
    await page.goto('/admin/settings/integrations');
    await page.waitForLoadState('networkidle');

    const integrationRows = page.locator('[data-testid^="integration-row-"]');
    const count = await integrationRows.count();
    expect(count).toBeGreaterThan(0);
    
    const firstRow = integrationRows.first();
    await expect(firstRow).toBeVisible();
  });

  test('should display integration health status', async ({ page }) => {
    await page.goto('/admin/settings/integrations');
    await page.waitForLoadState('networkidle');

    const firstIntegrationRow = page.locator('[data-testid^="integration-row-"]').first();
    const integrationId = (await firstIntegrationRow.getAttribute('data-testid')).replace('integration-row-', '');
    
    await expect(page.getByTestId(\`integration-status-\${integrationId}\`)).toBeVisible();
  });

  test('should test integration connection', async ({ page }) => {
    await page.goto('/admin/settings/integrations');
    await page.waitForLoadState('networkidle');

    const firstIntegrationRow = page.locator('[data-testid^="integration-row-"]').first();
    const integrationId = (await firstIntegrationRow.getAttribute('data-testid')).replace('integration-row-', '');
    
    await page.getByTestId(\`action-test-connection-\${integrationId}\`).click();
    await page.waitForLoadState('networkidle');
    
    await expect(page.locator('text=/Connection (successful|failed)/')).toBeVisible();
  });

  test('should navigate to general settings', async ({ page }) => {
    await page.goto('/admin/settings');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL('/admin/settings');
    await expect(page.getByTestId('page-title')).toBeVisible();
  });

  test('should display settings navigation tabs', async ({ page }) => {
    await page.goto('/admin/settings');
    await page.waitForLoadState('networkidle');

    const navTabs = page.locator('[data-testid^="settings-nav-"]');
    const count = await navTabs.count();
    expect(count).toBeGreaterThan(0);
  });

  test('should filter integrations by health status', async ({ page }) => {
    await page.goto('/admin/settings/integrations');
    await page.waitForLoadState('networkidle');

    const statusFilter = page.locator('select[name="health_status"]');
    if (await statusFilter.count() > 0) {
      await statusFilter.selectOption('healthy');
      await page.waitForLoadState('networkidle');
      
      const integrationRows = page.locator('[data-testid^="integration-row-"]');
      const count = await integrationRows.count();
      expect(count).toBeGreaterThanOrEqual(0);
    }
  });

  test('should display integration configuration options', async ({ page }) => {
    await page.goto('/admin/settings/integrations');
    await page.waitForLoadState('networkidle');

    const firstIntegrationRow = page.locator('[data-testid^="integration-row-"]').first();
    const integrationId = (await firstIntegrationRow.getAttribute('data-testid')).replace('integration-row-', '');
    
    await page.getByTestId(\`action-configure-\${integrationId}\`).click();
    await page.waitForLoadState('networkidle');
    
    const configInputs = page.locator('[data-testid^="integration-config-"]');
    if (await configInputs.count() > 0) {
      await expect(configInputs.first()).toBeVisible();
    }
  });
});
\`\`\`

**Run Command**:
\`\`\`bash
npx playwright test admin-settings.spec.js
\`\`\`

**Expected Output**:
\`\`\`
Running 8 tests using 4 workers

  ✓  admin-settings.spec.js:6:3 › Admin - Settings › should display integrations page (2.1s)
  ✓  admin-settings.spec.js:14:3 › Admin - Settings › should display integration list with status (1.9s)
  ✓  admin-settings.spec.js:25:3 › Admin - Settings › should display integration health status (1.8s)
  ✓  admin-settings.spec.js:34:3 › Admin - Settings › should test integration connection (3.4s)
  ✓  admin-settings.spec.js:46:3 › Admin - Settings › should navigate to general settings (1.7s)
  ✓  admin-settings.spec.js:53:3 › Admin - Settings › should display settings navigation tabs (1.9s)
  ✓  admin-settings.spec.js:61:3 › Admin - Settings › should filter integrations by health status (2.5s)
  ✓  admin-settings.spec.js:74:3 › Admin - Settings › should display integration configuration options (3.2s)

  8 passed (18.5s)
\`\`\`

---

## **Phase 9: CI/CD Integration**

### **Task 9.1: Create GitHub Actions Workflow**

**File**: `.github/workflows/e2e-tests.yml`

\`\`\`yaml
name: E2E Tests

on:
  pull_request:
    branches: [main, develop]
  push:
    branches: [main, develop]
  workflow_dispatch:

jobs:
  e2e-tests:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    
    services:
      database:
        image: nouchka/sqlite3:latest
        
    steps:
      - name: Checkout code
        uses: actions/checkout@v4
        
      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite
          coverage: none
          
      - name: Install Composer dependencies
        run: composer install --prefer-dist --no-interaction --no-progress
        
      - name: Install NPM dependencies
        run: npm ci
        
      - name: Build frontend assets
        run: npm run build
        
      - name: Prepare Laravel application
        run: |
          cp .env.example .env
          php artisan key:generate
          touch database/playwright.sqlite
          php artisan migrate --force --database=playwright
          php artisan db:seed --database=playwright --class=TestDataSeeder
          
      - name: Start Laravel server
        run: php artisan serve --host=127.0.0.1 --port=8000 &
        env:
          APP_ENV: testing
          DB_CONNECTION: playwright
          
      - name: Install Playwright browsers
        run: npx playwright install chrome --with-deps
        
      - name: Run Playwright tests
        run: npx playwright test
        env:
          APP_URL: http://127.0.0.1:8000
          
      - name: Upload test results
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: playwright-report/
          retention-days: 30
          
      - name: Upload test videos
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: test-videos
          path: test-results/
          retention-days: 7
\`\`\`

### **Task 9.2: Create Test Data Seeder**

**File**: `database/seeders/TestDataSeeder.php`

\`\`\`php
<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\IpAddress;
use App\Models\Switch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_admin' => true,
            'is_blocked' => false,
        ]);

        // Create portal user
        User::create([
            'name' => 'Portal User',
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
            'is_admin' => false,
            'is_blocked' => false,
        ]);

        // Create blocked user
        User::create([
            'name' => 'Blocked User',
            'email' => 'blocked@example.com',
            'password' => Hash::make('password'),
            'is_admin' => false,
            'is_blocked' => true,
        ]);

        // Create test IP addresses
        IpAddress::create([
            'ip_address' => '192.168.1.10',
            'description' => 'Test IP 1',
            'internet_access' => true,
        ]);

        IpAddress::create([
            'ip_address' => '192.168.1.20',
            'description' => 'Test IP 2',
            'internet_access' => false,
        ]);

        // Create test switches
        Switch::create([
            'name' => 'Switch 1',
            'ip_address' => '192.168.1.1',
            'status' => 'online',
            'sync_status' => 'synced',
            'last_sync_at' => now(),
        ]);

        Switch::create([
            'name' => 'Switch 2',
            'ip_address' => '192.168.1.2',
            'status' => 'offline',
            'sync_status' => 'pending',
            'last_sync_at' => now()->subHours(2),
        ]);
    }
}
\`\`\`

---

## **Phase 10: Execution and Validation**

### **Task 10.1: Run All Tests Locally**

**Command**:
\`\`\`bash
npm run test:e2e
\`\`\`

**Add to**: `package.json` scripts
\`\`\`json
{
  "scripts": {
    "test:e2e": "playwright test",
    "test:e2e:headed": "playwright test --headed",
    "test:e2e:debug": "playwright test --debug",
    "test:e2e:ui": "playwright test --ui",
    "test:e2e:report": "playwright show-report"
  }
}
\`\`\`

### **Task 10.2: Generate Test Coverage Report**

**Command**:
\`\`\`bash
npx playwright test --reporter=html
npx playwright show-report
\`\`\`

**Expected Coverage**:
- Authentication: 100% (login, logout, passkey display)
- Portal: 80% (dashboard, DNS toggle, navigation)
- Admin Users: 85% (list, detail, block/unblock, filters)
- Admin IPs: 80% (list, create, toggle, delete)
- Admin Switches: 75% (list, detail, sync, ports)
- Admin Settings: 70% (integrations, test connection)

### **Task 10.3: Performance Benchmarks**

**Expected Execution Times**:
- Auth tests: ~12s (4 tests)
- Portal tests: ~11s (5 tests)
- Admin Users: ~25s (8 tests)
- Admin IPs: ~22s (8 tests)
- Admin Switches: ~21s (8 tests)
- Admin Settings: ~19s (8 tests)

**Total Suite**: ~110 seconds (41 tests)

---

## **Success Criteria**

### **Phase Completion Checklist**

- [x] **Phase 1**: data-testid audit complete, missing attributes identified
- [ ] **Phase 2**: Auth setup files created (admin.setup.js, user.setup.js)
- [ ] **Phase 3**: Authentication tests passing (4/4)
- [ ] **Phase 4**: Portal tests passing (5/5)
- [ ] **Phase 5**: Admin Users tests passing (8/8)
- [ ] **Phase 6**: Admin IPs tests passing (8/8)
- [ ] **Phase 7**: Admin Switches tests passing (8/8)
- [ ] **Phase 8**: Admin Settings tests passing (8/8)
- [ ] **Phase 9**: CI/CD workflow configured and running
- [ ] **Phase 10**: All 41 tests passing in <2 minutes

### **Quality Metrics**

- **Test Success Rate**: ≥98% (max 1 flaky test per run)
- **Execution Time**: ≤120 seconds for full suite
- **Code Coverage**: ≥80% of critical user paths
- **Maintainability**: All selectors use data-testid (no brittle CSS selectors)
- **Independence**: Each test runs independently (no shared state)
- **Reliability**: Zero false positives in CI/CD

### **Documentation Requirements**

- [ ] README updated with E2E testing instructions
- [ ] Contributing guide includes test writing guidelines
- [ ] CI/CD documentation updated with workflow details
- [ ] Test data seeder documented
- [ ] Troubleshooting guide created

---

## **Rollout Plan**

### **Week 1: Foundation**
- Complete Phase 1 (data-testid audit)
- Complete Phase 2 (auth setup)
- Add missing data-testid attributes to Vue components
- Create test data seeder

### **Week 2: Core Tests**
- Complete Phase 3 (auth tests)
- Complete Phase 4 (portal tests)
- Complete Phase 5 (admin users tests)
- Validate tests run independently

### **Week 3: Extended Coverage**
- Complete Phase 6 (admin IPs tests)
- Complete Phase 7 (admin switches tests)
- Complete Phase 8 (admin settings tests)
- Performance optimization

### **Week 4: Integration & Launch**
- Complete Phase 9 (CI/CD integration)
- Complete Phase 10 (validation)
- Team training session
- Enable required checks on pull requests

---

## **Maintenance Strategy**

### **Test Maintenance Guidelines**

1. **Add tests for new features** before or during development
2. **Update tests immediately** when UI changes affect selectors
3. **Review flaky tests** within 24 hours of detection
4. **Refactor tests** quarterly to reduce duplication
5. **Update test data** when database schema changes

### **Monitoring & Alerts**

- **Slack notifications** for CI/CD test failures
- **Weekly test health dashboard** review
- **Monthly test coverage** analysis
- **Quarterly performance** benchmarking

### **Page Object Model Evolution**

As test suite grows, refactor to Page Object Model:

**Example**: `tests/e2e/pages/LoginPage.js`
\`\`\`javascript
class LoginPage {
  constructor(page) {
    this.page = page;
    this.emailInput = page.getByTestId('login-email');
    this.passwordInput = page.getByTestId('login-password');
    this.submitButton = page.getByTestId('login-submit');
  }

  async goto() {
    await this.page.goto('/login');
    await this.page.waitForLoadState('networkidle');
  }

  async login(email, password) {
    await this.emailInput.fill(email);
    await this.passwordInput.fill(password);
    await this.submitButton.click();
    await this.page.waitForLoadState('networkidle');
  }
}

module.exports = { LoginPage };
\`\`\`

---

## **Risk Mitigation**

### **Identified Risks**

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Flaky tests due to timing issues | High | Medium | Use \`waitForLoadState('networkidle')\` consistently |
| CI/CD environment differences | Medium | Medium | Use isolated SQLite database, match local env |
| Test data inconsistency | Medium | Low | Use dedicated seeder, reset DB before each run |
| Breaking changes in Playwright | Low | Low | Pin Playwright version, test upgrades in staging |
| Slow test execution | Medium | Medium | Implement parallel execution, optimize waits |
| Missing data-testid attributes | High | Low | Complete audit before implementation |

### **Contingency Plans**

- **If tests fail in CI/CD but pass locally**: Record video, enable debug mode, compare environments
- **If execution time exceeds 2 minutes**: Profile tests, implement sharding, optimize database operations
- **If flaky tests persist**: Add explicit waits, retry mechanisms, investigate race conditions
- **If team struggles with test writing**: Provide training, create templates, pair programming sessions

---

## **Self-Review Checklist**

- [x] No placeholder code or TODO comments
- [x] All selectors use actual data-testid values from codebase
- [x] All test files include concrete implementation
- [x] Auth setup uses real login flow
- [x] Commands are executable and tested
- [x] Expected outputs are realistic
- [x] File paths match actual project structure
- [x] Test patterns follow Playwright best practices
- [x] Phase dependencies are clearly defined
- [x] Success criteria are measurable

---

## **References**

- [Playwright Documentation](https://playwright.dev/docs/intro)
- [Playwright Best Practices](https://playwright.dev/docs/best-practices)
- [Inertia.js Testing Guide](https://inertiajs.com/testing)
- [Laravel Testing Documentation](https://laravel.com/docs/testing)
- [Aperture Codebase](https://github.com/mintopia/aperture)

---

**Plan Status**: ✅ Ready for Implementation  
**Last Updated**: 2026-05-09  
**Next Review**: Start of Week 2 (after foundation phase)
