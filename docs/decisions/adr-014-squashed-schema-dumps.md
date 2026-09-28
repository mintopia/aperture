# Decision: Squash migrations into per-connection schema dumps

Status: accepted
Date: 2026-09-28

## Context
56+ migrations contained create-then-drop churn. Tests run on SQLite in-memory and production runs MariaDB, so each needs a native schema.

## Decision
`database/schema/mysql-schema.sql` (MariaDB, the `mysql` connection) and `database/schema/sqlite-schema.sql` are committed Laravel schema dumps. Laravel loads the dump matching the active connection before running any remaining migrations. The dumps also record the squashed migrations, so existing databases treat them as already run.

Regenerate with `bin/schema-dump.sh` (needs a MariaDB server via `DB_*`/`DB_SOCKET`). It deletes every file in `database/migrations`, so only run it once all pending migrations have reached production. CI runs `bin/schema-dump.sh --check`, which fails when the committed dumps differ from a fresh build.

Default data (e.g. theme settings) lives in `SettingsSeeder`, not in migrations, because dumps carry schema only.

## Consequences
- Fresh installs must run `db:seed` for default settings (already required for roles).
- New migrations are added normally and apply after the dump; they are folded in at the next regeneration.
- Regenerating with unreleased migrations pending would skip them in production.

## Supersedes
None
