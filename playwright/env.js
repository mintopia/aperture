import fs from 'node:fs';
import path from 'node:path';

const LOCAL_BASE_URL = 'http://127.0.0.1:8000';
const DEFAULT_ALLOWED_HOSTS = [
    '127.0.0.1',
    'localhost',
    'aperture.local.js42.io',
    'hallowed-rincewind.ws.cloudagent.mintopia.net',
    'reverb.hallowed-rincewind.ws.cloudagent.mintopia.net',
    'vite.hallowed-rincewind.ws.cloudagent.mintopia.net',
];

export function readDotEnvValue(key) {
    const candidatePaths = [path.resolve(process.cwd(), '.env.playwright'), path.resolve(process.cwd(), '.env')];

    for (const envPath of candidatePaths) {
        if (!fs.existsSync(envPath)) {
            continue;
        }

        const lines = fs.readFileSync(envPath, 'utf8').split('\n');
        for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed || trimmed.startsWith('#')) continue;
            const separator = trimmed.indexOf('=');
            if (separator <= 0) continue;
            const envKey = trimmed.slice(0, separator).trim();
            if (envKey !== key) continue;
            return trimmed.slice(separator + 1).trim().replace(/^['"]|['"]$/g, '');
        }
    }

    return undefined;
}

function splitCsv(value) {
    if (!value) return [];
    return value
        .split(',')
        .map(entry => entry.trim())
        .filter(Boolean);
}

export function resolveAllowedBaseUrlHosts() {
    const hosts = [
        ...DEFAULT_ALLOWED_HOSTS,
        ...splitCsv(process.env.PLAYWRIGHT_ALLOWED_HOSTS),
        ...splitCsv(readDotEnvValue('PLAYWRIGHT_ALLOWED_HOSTS')),
        process.env.PUBLIC_APP_HOSTNAME,
        process.env.PUBLIC_REVERB_HOSTNAME,
        process.env.PUBLIC_VITE_HOSTNAME,
        readDotEnvValue('PUBLIC_APP_HOSTNAME'),
        readDotEnvValue('PUBLIC_REVERB_HOSTNAME'),
        readDotEnvValue('PUBLIC_VITE_HOSTNAME'),
    ].filter(Boolean);

    return new Set(hosts);
}

export function isSupportedBaseUrl(url) {
    try {
        const { hostname } = new URL(url);
        return resolveAllowedBaseUrlHosts().has(hostname);
    } catch {
        return false;
    }
}

export function resolveBaseUrl() {
    const explicit = process.env.PLAYWRIGHT_BASE_URL || process.env.E2E_BASE_URL;
    if (explicit) return explicit;

    const processAppUrl = process.env.APP_URL;
    if (processAppUrl && isSupportedBaseUrl(processAppUrl)) return processAppUrl;

    const dotEnvAppUrl = readDotEnvValue('APP_URL');
    if (dotEnvAppUrl && isSupportedBaseUrl(dotEnvAppUrl)) return dotEnvAppUrl;

    return LOCAL_BASE_URL;
}

export function buildPlaywrightEnv(baseURL = resolveBaseUrl()) {
    const playwrightDbConnection = process.env.PLAYWRIGHT_DB_CONNECTION || 'sqlite';
    // Keep Playwright on an isolated sqlite file by default. `:memory:` cannot be shared
    // between the setup artisan process and the long-lived web server process.
    const playwrightSqliteDatabase = process.env.PLAYWRIGHT_DB_DATABASE || path.resolve(process.cwd(), 'database', 'playwright.sqlite');
    // The "array" driver never persists session data beyond a single request lifecycle, so
    // a real browser flow that needs a session across two HTTP requests (e.g. GET /login to
    // pick up a CSRF token, then POST /login) always fails with a 419 against the long-lived
    // `php artisan serve` process. Use "file" by default so sessions survive between requests;
    // PLAYWRIGHT_SESSION_DRIVER can still override this for other drivers.
    const playwrightSessionDriver = process.env.PLAYWRIGHT_SESSION_DRIVER || 'file';
    const playwrightCacheDriver = process.env.PLAYWRIGHT_CACHE_DRIVER || 'array';
    const playwrightQueueConnection = process.env.PLAYWRIGHT_QUEUE_CONNECTION || 'sync';

    return {
        APP_ENV: process.env.APP_ENV || readDotEnvValue('APP_ENV') || 'playwright',
        APP_URL: baseURL,
        DB_CONNECTION: playwrightDbConnection,
        DB_DATABASE: playwrightDbConnection === 'sqlite' ? playwrightSqliteDatabase : process.env.PLAYWRIGHT_DB_DATABASE || process.env.DB_DATABASE || readDotEnvValue('DB_DATABASE'),
        SESSION_DRIVER: playwrightSessionDriver,
        CACHE_DRIVER: playwrightCacheDriver,
        CACHE_STORE: process.env.PLAYWRIGHT_CACHE_STORE || playwrightCacheDriver,
        QUEUE_CONNECTION: playwrightQueueConnection,
        REDIS_HOST: process.env.REDIS_HOST || readDotEnvValue('REDIS_HOST') || '127.0.0.1',
        REDIS_PORT: process.env.REDIS_PORT || readDotEnvValue('REDIS_PORT') || '6379',
        // The default .env points at the docker-compose "reverb" hostname, which isn't
        // resolvable when running Playwright against a bare `php artisan serve`. Broadcasting
        // an event (e.g. the login audit log) would otherwise throw and turn every
        // authenticated request into a 500. Mirror phpunit.xml's BROADCAST_DRIVER=log so
        // broadcasts are swallowed to the log instead of attempting a real Reverb connection.
        BROADCAST_DRIVER: process.env.PLAYWRIGHT_BROADCAST_DRIVER || readDotEnvValue('PLAYWRIGHT_BROADCAST_DRIVER') || 'log',
    };
}

export const localFallbackUrls = ['http://localhost:8000', LOCAL_BASE_URL];
