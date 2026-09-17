import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { cpSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);

export default defineConfig({
    resolve: { preserveSymlinks: true, dedupe: ['react','react-dom','pdfjs-dist'] },
    plugins: [
        laravel({
            input: ['resources/js/knowledge-reader.jsx', 'resources/js/staff-calendar.js'],
            refresh: true,
        }),
        { name: 'local-pdf-assets', closeBundle() {
            const root=dirname(require.resolve('pdfjs-dist/package.json'));
            for (const folder of ['cmaps','standard_fonts','wasm']) {
                cpSync(resolve(root,folder),resolve('public/build/pdf-assets',folder),{recursive:true});
            }
        } },
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
