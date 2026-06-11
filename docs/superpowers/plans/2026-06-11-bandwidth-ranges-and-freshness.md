# Bandwidth range alignment, chart error states, Prometheus data freshness

Date: 2026-06-11
Branch: feature/improvements

## Problem (production-verified)

Dashboard "Top Bandwidth" shows users.weekly_* (7-day Prometheus sync) but no
graph in the app can display more than 4 days, so weekly totals are never
visualizable. The user page labels the 4d range as "72H" (Dashboard.vue maps
value '4d' to label '72H'; Users/Show.vue renders `r === '4d' ? '72H' : ...`).
Users/Show.vue does not destructure/display `bandwidthError`, so failures are
silent. PrometheusTester only checks /api/v1/status/buildinfo, so the
integration shows "Healthy" while the ntopng metric feed has been dead for
4+ days (zero series even for unfiltered totals while users are online).

## Fix design

### A. Range alignment (+7d)
- `App\Http\Requests\Admin\BandwidthRequest`: `in:1h,24h,4d,7d`.
- `resources/js/Pages/Admin/Users/Show.vue`: ranges `['1h','24h','4d','7d']`,
  correct labels (1H/24H/4D/7D — remove the 72H mislabel), display
  `bandwidthError` like Ips/Show does.
- `resources/js/Pages/Admin/Ips/Show.vue`: add `'7d'` to ranges.
- `resources/js/Pages/Admin/Dashboard.vue`: fix `{value:'4d',label:'72H'}` to
  `4D`, add `{value:'7d',label:'7D'}`.
- Backend already supports 7d (rangeToSeconds/resolveStep handle it).

### B. Prometheus data freshness in connection test
- `App\Services\Integration\PrometheusTester`: after buildinfo succeeds, run
  an instant query for the received-bytes metric (config override or default
  `ntopng_host_bytes_rcvd`). If the query API call fails, no series exist, or
  the newest sample is older than a staleness threshold (15 minutes), return a
  failed TestConnectionResult whose message states the data problem and the
  last-sample age. Reachability-only success is no longer possible.

## Out of scope
- Restarting the ntopng exporter / Prometheus scrape (infrastructure).
- Scheduled freshness checks (health still reflects the latest manual test).

## Workflow log / handover

```json
{
  "task": "Bandwidth range alignment (+7d), chart error states, Prometheus freshness in tester",
  "gates": {
    "coverage": true,
    "tests": true,
    "formatting": true,
    "review": true,
    "ui": true
  },
  "gate_notes": "coverage: BandwidthRequest + PrometheusTester 100% lines; all changed Vue lines covered (page-level Vue gaps pre-existing). ui: impeccable audit 17/20, single medium (error not announced) fixed with role=status across all three pages.",
  "actions": [
    {"type": "test-creation", "agent": "test-automator", "description": "Red tests: 7d range on 3 endpoints (+72h stays 422), PrometheusTester freshness contract, Vue range buttons/labels and Users/Show error display", "updated": ["tests/Feature/Admin/DashboardBandwidthTest.php", "tests/Feature/Admin/UserControllerTest.php", "tests/Feature/Admin/IpAddressControllerTest.php", "tests/Unit/Services/Integration/PrometheusTesterTest.php", "tests/js/Pages/Admin/Users/Show.spec.js", "tests/js/Pages/Admin/Dashboard.spec.js", "tests/js/Pages/Admin/Ips/ShowBandwidth.spec.js"], "success": true},
    {"type": "implementation", "agent": "laravel-expert", "description": "BandwidthRequest in:1h,24h,4d,7d; range buttons 1H/24H/4D/7D (72H mislabel fixed); Users/Show bandwidthError display; PrometheusTester instant-query freshness check (no-series/stale>15min/query-error => failed result)", "updated": ["app/Http/Requests/Admin/BandwidthRequest.php", "app/Services/Integration/PrometheusTester.php", "resources/js/Pages/Admin/Users/Show.vue", "resources/js/Pages/Admin/Dashboard.vue", "resources/js/Pages/Admin/Ips/Show.vue"], "success": true},
    {"type": "code-review", "agent": "code-reviewer", "description": "Approved except two mediums: 2 uncovered guard lines in PrometheusTester; 1 rector style hit in its test", "problems": {"medium": ["PrometheusTester lines 114/120 uncovered", "rector NewlineAfterStatementRector in PrometheusTesterTest"]}, "success": false},
    {"type": "test-creation", "agent": "test-automator", "description": "Guard-branch tests (malformed series entry, string timestamp) -> PrometheusTester 100%; rector hit fixed", "updated": ["tests/Unit/Services/Integration/PrometheusTesterTest.php"], "success": true},
    {"type": "qa", "agent": "qa-expert", "description": "PHP 2738 green x3 runs, vitest 1560 green x3, touched PHP files 100% lines, all changed Vue lines covered, npm build OK", "success": true},
    {"type": "ui-audit", "agent": "audit", "description": "Code-level impeccable audit 17/20 (live env unavailable): one medium in changed code (error paragraph not announced to AT)", "problems": {"medium": ["bandwidth-error <p> lacks role=status"]}, "success": false},
    {"type": "implementation", "agent": "vue-expert", "description": "role=status added to bandwidth-error on all three pages + test assertion; full vitest green, build OK", "updated": ["resources/js/Pages/Admin/Users/Show.vue", "resources/js/Pages/Admin/Dashboard.vue", "resources/js/Pages/Admin/Ips/Show.vue", "tests/js/Pages/Admin/Users/Show.spec.js"], "success": true}
  ]
}
```
