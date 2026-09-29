import fs from 'node:fs';
import path from 'node:path';
import { test as base, expect } from '@playwright/test';
import { saveLoginState, artisan } from './fixtures.js';

const password = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'playwright-password';

export const test = base.extend({
    workerStorageState: [
        async ({ browser }, use, workerInfo) => {
            const email = `pw-worker-admin-${workerInfo.parallelIndex}@example.test`;
            const file = path.resolve(process.cwd(), 'playwright/.auth', `worker-${workerInfo.parallelIndex}.json`);
            fs.mkdirSync(path.dirname(file), { recursive: true });

            artisan(
                'tinker',
                `--execute=Illuminate\\Database\\Eloquent\\Model::unguarded(function () { $u = App\\Models\\User::firstOrNew(['email' => '${email}']); $u->nickname = 'pw-worker-admin-${workerInfo.parallelIndex}'; $u->password = '${password}'; $u->save(); $u->roles()->syncWithoutDetaching(App\\Models\\Role::whereIn('code', ['admin', 'user'])->pluck('id')->all()); });`,
            );
            await saveLoginState(browser, { email, password }, file);

            await use(file);
        },
        { scope: 'worker' },
    ],
    storageState: ({ workerStorageState }, use) => use(workerStorageState),
});

export { expect };
