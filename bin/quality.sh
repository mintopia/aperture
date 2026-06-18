#!/usr/bin/env bash
set -euo pipefail

CYAN='\033[0;36m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

pass() { echo -e "${GREEN}✓ $1${NC}"; }
fail() { echo -e "${RED}✕ $1${NC}"; exit 1; }
step() { echo -e "\n${CYAN}▸ $1${NC}"; }

step "PHP: Laravel Pint (code formatting)"
vendor/bin/pint --test --format agent && pass "Pint" || fail "Pint formatting issues found. Run: vendor/bin/pint --format agent"

step "PHP: PHPStan Level 8 (static analysis)"
vendor/bin/phpstan analyse --no-progress && pass "PHPStan" || fail "PHPStan errors found"

step "PHP: Rector (code quality)"
vendor/bin/rector process --dry-run --no-progress-bar && pass "Rector" || fail "Rector suggestions found. Run: vendor/bin/rector process"

step "PHP: PHPUnit (parallel tests + coverage)"
PHP_OUTPUT=$(mktemp)
XDEBUG_MODE=coverage php artisan test --parallel --compact --coverage-text --coverage-html=storage/coverage/php --coverage-clover=storage/coverage/php/clover.xml 2>&1 | tee "$PHP_OUTPUT"
PHP_EXIT=${PIPESTATUS[0]}
if [ "$PHP_EXIT" -ne 0 ]; then
    rm -f "$PHP_OUTPUT"
    fail "PHPUnit tests failed"
fi
PHP_COVERAGE=$(sed 's/\x1b\[[0-9;]*m//g' "$PHP_OUTPUT" | grep "^  Lines:" | head -1 | sed 's/.*Lines:[[:space:]]*//' | sed 's/%.*//')
rm -f "$PHP_OUTPUT"
PHP_COVERAGE=$(echo "$PHP_COVERAGE" | tr -d ' ')
if [ -z "$PHP_COVERAGE" ]; then
    fail "Could not parse PHP line coverage"
fi
if [ "$PHP_COVERAGE" != "100.00" ]; then
    fail "PHP line coverage is ${PHP_COVERAGE}%, required 100.00%"
fi
pass "PHPUnit (parallel, ${PHP_COVERAGE}% line coverage)"

step "JS: ESLint (linting)"
npx eslint resources/js/ && pass "ESLint" || fail "ESLint errors found. Run: npm run lint:fix"

step "JS: Prettier (formatting)"
npx prettier --check resources/js/ resources/css/ && pass "Prettier" || fail "Prettier issues found. Run: npm run format"

step "JS: Vitest (unit tests + coverage)"
JS_OUTPUT=$(mktemp)
npx vitest run tests/js/ --coverage 2>&1 | tee "$JS_OUTPUT"
JS_EXIT=${PIPESTATUS[0]}
if [ "$JS_EXIT" -ne 0 ]; then
    rm -f "$JS_OUTPUT"
    fail "Vitest tests failed"
fi
JS_COVERAGE=$(grep "All files" "$JS_OUTPUT" | awk -F'|' '{print $5}' | tr -d ' ')
rm -f "$JS_OUTPUT"
if [ -z "$JS_COVERAGE" ]; then
    fail "Could not parse JS line coverage"
fi
JS_LINES_INT=$(echo "$JS_COVERAGE" | awk -F'.' '{print $1}')
if [ "$JS_LINES_INT" != "100" ]; then
    fail "JS line coverage is ${JS_COVERAGE}%, required 100%"
fi
pass "Vitest (${JS_COVERAGE}% line coverage)"

step "JS: Vite build"
[[ -f public/hot ]] && cp public/hot public/hot.bak
npm run build && pass "Build" || { [[ -f public/hot.bak ]] && mv public/hot.bak public/hot; fail "Vite build failed"; }
[[ -f public/hot.bak ]] && mv public/hot.bak public/hot

echo -e "\n${GREEN}══════════════════════════════════════${NC}"
echo -e "${GREEN}  All quality checks passed!${NC}"
echo -e "${GREEN}══════════════════════════════════════${NC}"
