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

step "PHP: PHPUnit (tests)"
php artisan test --compact && pass "PHPUnit" || fail "PHPUnit tests failed"

step "JS: ESLint (linting)"
npx eslint resources/js/ && pass "ESLint" || fail "ESLint errors found. Run: npm run lint:fix"

step "JS: Prettier (formatting)"
npx prettier --check resources/js/ resources/css/ && pass "Prettier" || fail "Prettier issues found. Run: npm run format"

step "JS: Vitest (unit tests)"
npx vitest run tests/js/ && pass "Vitest" || fail "Vitest tests failed"

step "JS: Vite build"
npm run build && pass "Build" || fail "Vite build failed"

echo -e "\n${GREEN}══════════════════════════════════════${NC}"
echo -e "${GREEN}  All quality checks passed!${NC}"
echo -e "${GREEN}══════════════════════════════════════${NC}"
