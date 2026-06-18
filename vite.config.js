import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export function resolveHmrHost(appUrl = process.env.APP_URL) {
    if (!appUrl) {
        return 'localhost';
    }

    try {
        return new URL(appUrl).hostname || 'localhost';
    } catch {
        return 'localhost';
    }
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const hmrClientPort = env.VITE_HMR_PORT ? Number(env.VITE_HMR_PORT) : undefined;
    const hmrServerPort = env.VITE_HMR_SERVER_PORT ? Number(env.VITE_HMR_SERVER_PORT) : undefined;

    return {
        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                ],
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            tailwindcss(),
        ],
        resolve: {
            alias: {
                '@': '/resources/js',
                'ziggy-js': '/vendor/tightenco/ziggy/dist/index.esm.js',
            },
        },
        server: {
            host: '0.0.0.0',
            cors: true,
            allowedHosts: [
                env.VITE_HMR_HOST
            ],
            hmr: {
                host: env.VITE_HMR_HOST || resolveHmrHost(env.APP_URL),
                port: hmrServerPort,
                clientPort: hmrClientPort,
                protocol: env.VITE_HMR_PROTOCOL || undefined,
            },
        },
    };
});
