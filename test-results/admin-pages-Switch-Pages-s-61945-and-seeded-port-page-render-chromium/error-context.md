# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: admin-pages.spec.js >> Switch Pages >> switch detail and seeded port page render
- Location: tests/e2e/admin-pages.spec.js:64:5

# Error details

```
Error: expect(locator).toBeVisible() failed

Locator: getByText('Playwright Switch')
Expected: visible
Timeout: 5000ms
Error: element(s) not found

Call log:
  - Expect "toBeVisible" with timeout 5000ms
  - waiting for getByText('Playwright Switch')

```

# Page snapshot

```yaml
- generic [ref=e3]:
  - generic [ref=e5]: A
  - heading "Connect to Network" [level=1] [ref=e6]
  - paragraph [ref=e7]: Scan the QR code or enter the code below
  - img [ref=e10]
  - paragraph [ref=e25]:
    - text: Visit
    - link "https://borealis.entropylan.party/auth" [ref=e26] [cursor=pointer]:
      - /url: https://borealis.entropylan.party/auth
  - generic [ref=e27]:
    - paragraph [ref=e28]: Enter this code
    - generic [ref=e29]: YW92
  - generic [ref=e30]:
    - paragraph [ref=e31]: How to connect
    - list [ref=e32]:
      - listitem [ref=e33]:
        - generic [ref=e34]: "1"
        - generic [ref=e35]: Scan the QR code or visit the URL above on a device with internet
      - listitem [ref=e36]:
        - generic [ref=e37]: "2"
        - generic [ref=e38]: Enter the code shown above when prompted
      - listitem [ref=e39]:
        - generic [ref=e40]: "3"
        - generic [ref=e41]: Authorize the connection — you'll be connected automatically
  - generic [ref=e45]: Waiting for authorization…
```

# Test source

```ts
  1   | import { test, expect } from '@playwright/test';
  2   | 
  3   | test.describe('IP Pages', () => {
  4   |     test('IP list renders data table', async ({ page }) => {
  5   |         await page.goto('/admin/ips');
  6   |         await expect(page.getByTestId('page-title')).toContainText('IP Addresses');
  7   |         await expect(page.getByTestId('data-table')).toBeVisible();
  8   |     });
  9   | 
  10  |     test('IP list has search inputs', async ({ page }) => {
  11  |         await page.goto('/admin/ips');
  12  |         await expect(page.getByTestId('search-address')).toBeVisible();
  13  |         await expect(page.getByTestId('search-nickname')).toBeVisible();
  14  |     });
  15  | 
  16  |     test('IP list has search button', async ({ page }) => {
  17  |         await page.goto('/admin/ips');
  18  |         await expect(page.getByTestId('action-search')).toBeVisible();
  19  |     });
  20  | 
  21  |     test('IP list has pagination when multiple pages', async ({ page }) => {
  22  |         await page.goto('/admin/ips');
  23  |         // Pagination only renders when last_page > 1
  24  |         const pagination = page.getByTestId('pagination');
  25  |         // It may or may not exist depending on data volume
  26  |         const tableExists = await page.getByTestId('data-table').isVisible();
  27  |         expect(tableExists).toBe(true);
  28  |     });
  29  | 
  30  |     test('IP create page renders form', async ({ page }) => {
  31  |         await page.goto('/admin/ips/create');
  32  |         await expect(page.getByTestId('page-title')).toContainText('Add IP Address');
  33  |         await expect(page.getByTestId('form-field-address')).toBeVisible();
  34  |         await expect(page.getByTestId('form-field-comment')).toBeVisible();
  35  |         await expect(page.getByTestId('field-allow')).toBeVisible();
  36  |         await expect(page.getByTestId('field-limit')).toBeVisible();
  37  |         await expect(page.getByTestId('action-submit')).toBeVisible();
  38  |     });
  39  | });
  40  | 
  41  | test.describe('Switch Pages', () => {
  42  |     test('switch list renders', async ({ page }) => {
  43  |         await page.goto('/admin/switches');
  44  |         await expect(page.getByTestId('page-title')).toContainText('Switches');
  45  |         const emptyState = page.getByTestId('empty-state');
  46  |         const switchesTable = page.getByTestId('switches-table');
  47  |         const hasEmpty = await emptyState.isVisible().catch(() => false);
  48  |         const hasTable = await switchesTable.isVisible().catch(() => false);
  49  |         expect(hasEmpty || hasTable).toBe(true);
  50  |     });
  51  | 
  52  |     test('switch list shows status pills when data is present', async ({ page }) => {
  53  |         await page.goto('/admin/switches');
  54  |         const pills = page.locator('[data-testid="status-pill"]');
  55  |         const switchesTable = page.getByTestId('switches-table');
  56  |         const tableVisible = await switchesTable.isVisible().catch(() => false);
  57  |         if (tableVisible) {
  58  |             await expect(pills.first()).toBeVisible();
  59  |         } else {
  60  |             await expect(page.getByTestId('empty-state')).toBeVisible();
  61  |         }
  62  |     });
  63  | 
  64  |     test('switch detail and seeded port page render', async ({ page }) => {
  65  |         await page.goto('/admin/switches');
> 66  |         await expect(page.getByText('Playwright Switch')).toBeVisible();
      |                                                           ^ Error: expect(locator).toBeVisible() failed
  67  | 
  68  |         await page.getByText('Playwright Switch').click();
  69  |         await expect(page.getByTestId('page-title')).toContainText('Playwright Switch');
  70  |         await expect(page.getByTestId('data-table')).toBeVisible();
  71  | 
  72  |         await page.getByTestId('data-table-row').first().click();
  73  |         await expect(page.getByTestId('page-title')).toContainText('Gi1/0/1');
  74  |         await expect(page.getByTestId('port-status')).toBeVisible();
  75  |         await expect(page.getByTestId('port-switch-link')).toContainText('Playwright Switch');
  76  |     });
  77  | });
  78  | 
  79  | test.describe('DHCP Pages', () => {
  80  |     test('DHCP index renders stat cards', async ({ page }) => {
  81  |         await page.goto('/admin/dhcp');
  82  |         await expect(page.getByTestId('page-title')).toContainText('DHCP');
  83  |         const statCards = page.locator('[data-testid="stat-card"]');
  84  |         await expect(statCards.first()).toBeVisible();
  85  |     });
  86  | 
  87  |     test('DHCP index has link to leases page', async ({ page }) => {
  88  |         await page.goto('/admin/dhcp');
  89  |         const leaseLink = page.locator('a[href*="leases"]');
  90  |         await expect(leaseLink).toBeVisible();
  91  |     });
  92  | 
  93  |     test('DHCP leases page renders', async ({ page }) => {
  94  |         await page.goto('/admin/dhcp/leases');
  95  |         await expect(page.getByTestId('page-title')).toContainText('DHCP Leases');
  96  |         await expect(page.getByTestId('data-table')).toBeVisible();
  97  |     });
  98  | });
  99  | 
  100 | test.describe('Content Page', () => {
  101 |     test('content page renders', async ({ page }) => {
  102 |         await page.goto('/admin/content');
  103 |         await expect(page.getByTestId('page-title')).toContainText('Content Blocks');
  104 |     });
  105 | 
  106 |     test('content page shows empty state or data table', async ({ page }) => {
  107 |         await page.goto('/admin/content');
  108 |         const emptyState = page.getByTestId('empty-state');
  109 |         const dataTable = page.getByTestId('data-table');
  110 |         // One of these should be visible
  111 |         const hasEmpty = await emptyState.isVisible().catch(() => false);
  112 |         const hasTable = await dataTable.isVisible().catch(() => false);
  113 |         expect(hasEmpty || hasTable).toBe(true);
  114 |     });
  115 | });
  116 | 
```