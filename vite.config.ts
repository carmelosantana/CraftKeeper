import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fontsource } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            // Self-hosted via local @fontsource packages (no runtime or
            // build-time CDN dependency — see docs/architecture/decisions.md
            // Task 3 entry). Hanken Grotesk is the UI font; JetBrains Mono
            // covers paths/keys/code/console/diffs per design-tokens.json.
            fonts: [
                fontsource('Hanken Grotesk', {
                    weights: [400, 500, 600, 700, 800],
                    display: 'swap',
                    // The optional `fontaine` package would fine-tune
                    // fallback-font metrics; skip it (YAGNI) rather than add
                    // another dependency for a purely cosmetic CLS tweak.
                    optimizedFallbacks: false,
                }),
                fontsource('JetBrains Mono', {
                    weights: [400, 500, 600],
                    display: 'swap',
                    optimizedFallbacks: false,
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
