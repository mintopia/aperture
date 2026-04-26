import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        globals: true,
        exclude: ['tests/e2e/**', 'node_modules/**', '.worktrees/**'],
        coverage: {
            provider: 'v8',
            reportsDirectory: 'storage/coverage/js',
            include: [
                'resources/js/Components/UI/**/*.vue',
                'resources/js/Pages/Admin/Switches/Ports/Show.vue',
                'resources/js/Pages/Admin/Switches/Index.vue',
                'resources/js/Pages/Admin/Switches/Show.vue',
                'resources/js/Pages/Admin/Dashboard.vue',
                'resources/js/Pages/Admin/Macs/Index.vue',
                'resources/js/Pages/Admin/Macs/Show.vue',
                'resources/js/Pages/Admin/AuditLog/Index.vue',
                'resources/js/Components/Admin/EventFeed.vue',
                'resources/js/Components/Blocks/DnsWarningBlock.vue',
                'resources/js/composables/**/*.js',
                'resources/js/helpers.js',
                'resources/js/utils/**/*.js',
            ],
            exclude: ['resources/js/app.js'],
            reporter: ['text', 'html', 'clover'],
        },
    },
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
            'ziggy-js': resolve(__dirname, 'vendor/tightenco/ziggy/dist/index.esm.js'),
        },
    },
});
