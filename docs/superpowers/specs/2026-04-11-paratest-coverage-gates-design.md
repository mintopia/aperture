# ParaTest + Coverage Gates

**Date:** 2026-04-11  
**Status:** Approved  
**Scope:** Test infrastructure — parallel execution and coverage enforcement

---

## Summary

Add ParaTest for parallel PHP test execution and enforce 100% line coverage gates for both PHP and JS test suites in the quality script.

## Motivation

- CLAUDE.md requires 100% code coverage and parallel tests where possible
- Current setup runs PHPUnit sequentially with no coverage reporting
- Vitest coverage is configured but not enforced in the quality script

## Changes

### 1. Install ParaTest

```bash
composer require --dev brianium/paratest
```

ParaTest runs PHPUnit test suites across multiple processes. Each process gets its own `:memory:` SQLite database automatically — no configuration needed.

### 2. PHPUnit XML — No Changes Required

The existing `phpunit.xml` already defines:
- Source directory: `app/`
- Test suites: `tests/Unit` and `tests/Feature`
- SQLite in-memory DB with array cache/session

Coverage reporting flags are passed via the CLI (ParaTest/PHPUnit args), not embedded in the XML.

### 3. Quality Script (`bin/quality.sh`)

#### PHP Test Step

Replace:
```bash
php artisan test --compact
```

With:
```bash
XDEBUG_MODE=coverage php artisan test --parallel --coverage-text --coverage-html=storage/coverage/php --coverage-clover=storage/coverage/php/clover.xml
```

After execution, parse the text output for the `Lines:` percentage. If not `100.00%`, fail with a message showing the actual coverage.

#### JS Test Step

Replace:
```bash
npx vitest run tests/js/
```

With:
```bash
npx vitest run tests/js/ --coverage
```

Parse the text summary output for the `% Lines` column in the `All files` row. If not `100`, fail with a message.

### 4. Coverage Threshold Enforcement

Both PHP and JS coverage checks follow the same pattern:

1. Run tests with coverage, capturing output
2. Extract the line coverage percentage from the text report
3. Compare against 100%
4. If below threshold: print the actual percentage and fail
5. If at threshold: pass

The enforcement is done in bash using `grep`/`awk` on the text output — no additional dependencies needed.

### 5. Storage

| Suite | Directory | Reporters |
|-------|-----------|-----------|
| PHP | `storage/coverage/php/` | text, html, clover |
| JS | `storage/coverage/js/` | text, html, clover (already configured in vitest.config.js) |

Ensure `storage/coverage/` is gitignored.

### 6. Composer Script

Add to `composer.json` scripts:
```json
"quality": "bash bin/quality.sh"
```

This enables `composer quality` as documented in CLAUDE.md.

## Non-Goals

- No changes to test structure or individual tests
- No new test files as part of this change
- No CI pipeline changes (those can use the clover XML later)

## Risks

- **Coverage parse fragility:** If PHPUnit or Vitest change their text output format, the grep/awk parsing could break. Mitigation: the patterns are simple and stable across major versions.
- **ParaTest + Xdebug overhead:** Coverage collection with parallel processes is slower than without coverage. This is expected and acceptable for the quality gate.
