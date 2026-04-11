import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import globals from 'globals';

export default [
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    {
        languageOptions: {
            globals: {
                ...globals.browser,
                route: 'readonly',
                axios: 'readonly',
                Echo: 'readonly',
            },
        },
        rules: {
            'vue/html-indent': ['warn', 4],
            'vue/script-indent': ['warn', 4],
            'indent': ['warn', 4],
            'vue/multi-word-component-names': 'off',
            'vue/no-v-html': 'off',
            'vue/no-v-text-v-html-on-component': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/html-self-closing': 'off',
            'vue/html-closing-bracket-newline': 'off',
            'no-unused-vars': [
                'error',
                {
                    argsIgnorePattern: '^_',
                    caughtErrorsIgnorePattern: '^_',
                },
            ],
        },
    },
    {
        ignores: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'storage/**',
            'resources/js/highlight.min.js',
        ],
    },
];
