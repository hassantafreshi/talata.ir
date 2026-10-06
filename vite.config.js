import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// No external font/CDN at runtime: fonts are self-hosted from public/fonts (docs/PERFORMANCE_BUDGET.md).
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/print.js'],
            refresh: true,
        }),
    ],
    build: {
        target: 'es2020', // BigInt (exact money math) needs ES2020; Safari 14+, Chrome 67+,
        cssCodeSplit: true,
    },
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
