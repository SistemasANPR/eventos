import js from '@eslint/js';
import globals from 'globals';
import jsxA11y from 'eslint-plugin-jsx-a11y';
import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';

// Sin `any`, sin supresiones a ojo (AGENTS.md, «Forma de trabajar»).
export default tseslint.config(
    { ignores: ['resources/js/components/ui/**'] },
    js.configs.recommended,
    ...tseslint.configs.strict,
    jsxA11y.flatConfigs.recommended,
    reactHooks.configs.flat.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: { globals: globals.browser },
        rules: {
            '@typescript-eslint/no-explicit-any': 'error',
            '@typescript-eslint/ban-ts-comment': 'error',
            'no-restricted-syntax': [
                'error',
                {
                    selector: 'JSXAttribute[name.name="dangerouslySetInnerHTML"]',
                    message: 'No se usa dangerouslySetInnerHTML (resources/js/CLAUDE.md §7).',
                },
            ],
        },
    },
);
