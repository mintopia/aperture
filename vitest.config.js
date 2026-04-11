import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        globals: true,
        exclude: ['tests/e2e/**', 'node_modules/**'],
        coverage: {
            provider: 'v8',
            reportsDirectory: 'storage/coverage/js',
            include: ['resources/js/**/*.{js,vue}'],
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
