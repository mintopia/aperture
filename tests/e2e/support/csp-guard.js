import { test as base, expect } from '@playwright/test';

export const test = base.extend({
    cspGuard: [
        async ({ context }, use) => {
            const violations = [];
            context.on('console', (msg) => {
                if (/Content Security Policy/i.test(msg.text())) {
                    violations.push(`${msg.text()} (page: ${msg.page()?.url()})`);
                }
            });

            await use();

            expect(violations, 'CSP violations logged during the test').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };
