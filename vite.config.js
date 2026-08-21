import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        // Force IPv4. Windows IPv6 loopback (::1) transforms are ~4x slower,
        // which made every first sidebar click feel like seconds.
        host: '0.0.0.0',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        // Pre-transform all pages at server start so that the first click on a
        // sidebar link does not wait for a slow on-demand compile (Windows).
        warmup: {
            clientFiles: ['./resources/js/pages/*.vue'],
        },
    },
});
