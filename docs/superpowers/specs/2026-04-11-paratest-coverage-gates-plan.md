# Implementation Plan: ParaTest + Coverage Gates

**Spec:** [2026-04-11-paratest-coverage-gates-design.md](2026-04-11-paratest-coverage-gates-design.md)  
**Date:** 2026-04-11

---

## Steps

### Step 1: Install ParaTest

```bash
composer require --dev brianium/paratest
```

**Verify:** `vendor/bin/paratest --version` runs successfully.

### Step 2: Verify ParaTest runs tests

```bash
XDEBUG_MODE=off php artisan test --parallel --compact
```

**Verify:** All 76 tests pass with parallel execution. No database conflicts.

### Step 3: Verify coverage collection works

```bash
XDEBUG_MODE=coverage php artisan test --parallel --coverage-text
```

**Verify:** Coverage text output appears showing line coverage percentages for `app/` source files.

### Step 4: Update quality script — PHP tests with coverage

**File:** `bin/quality.sh`

Replace the PHP test step with:
1. Run ParaTest with `XDEBUG_MODE=coverage`, capturing output to a temp file
2. Display the output
3. Extract the `Lines:` percentage using grep/awk
4. Compare against 100.00%
5. Pass or fail accordingly

The step should output something like:
```
✓ PHPUnit (parallel, 100.00% line coverage)
```
or:
```
✕ PHPUnit line coverage is 87.32%, required 100.00%
```

### Step 5: Update quality script — JS tests with coverage

**File:** `bin/quality.sh`

Replace the JS test step with:
1. Run `npx vitest run tests/js/ --coverage`, capturing output
2. Display the output
3. Extract the `All files` line coverage from the table output
4. Compare against 100%
5. Pass or fail accordingly

### Step 6: Add composer quality script

**File:** `composer.json`

Add to the `scripts` section:
```json
"quality": "bash bin/quality.sh"
```

### Step 7: Ensure coverage directories are gitignored

**File:** `.gitignore`

Verify or add:
```
storage/coverage/
```

### Step 8: Run full quality suite

```bash
bash bin/quality.sh
```

**Verify:** All steps run. Coverage reports are generated. The script passes or fails appropriately based on coverage thresholds.

---

## File Change Summary

| File | Action |
|------|--------|
| `composer.json` | Add paratest dependency + quality script |
| `composer.lock` | Auto-updated by composer |
| `bin/quality.sh` | Replace PHP + JS test steps with coverage-enforcing versions |
| `.gitignore` | Add `storage/coverage/` if not present |

## Test Strategy

- Run existing 76 PHP tests through ParaTest to confirm parallelism works
- Run existing 115 JS tests with coverage flag to confirm reporting works
- Run full quality script end-to-end
- No new tests needed — this is infrastructure, not application code
