import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import ui from '@nuxt/ui/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        // SSR is disabled: this app renders client-side only, and Nuxt UI's
        // runtime (#imports specifiers) is not resolvable in Vite's SSR
        // environment.
        inertia({ ssr: false }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        ui({
            router: 'inertia',
            // The starter kit's cookie-based appearance system owns the
            // `dark` class; Nuxt UI's own color mode (VueUse useDark) would
            // fight it and strip the class on mount.
            colorMode: false,
            ui: {
                // Brand ramps defined in resources/css/app.css @theme
                // (openyacht repo, docs/branding.md). The ICS locker has
                // no green, so success uses the complementary starboard
                // ramp rather than a recoloured locker value.
                colors: {
                    primary: 'signal-blue',
                    info: 'signal-blue',
                    error: 'signal-red',
                    warning: 'signal-yellow',
                    success: 'starboard',
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
});
