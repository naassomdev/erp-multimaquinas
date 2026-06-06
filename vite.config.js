/**
 * Vite 7 — bundler do frontend do ERP Multimáquinas.
 *
 * Modo DEV  (`npm run dev`)   → escreve `public/hot` com a URL do dev server.
 *                               O helper PHP detecta esse arquivo e injeta o
 *                               `@vite/client` para HMR.
 *
 * Modo BUILD (`npm run build`) → gera `public/build/` com manifest.json
 *                               (assets hashados); o helper PHP lê o manifest
 *                               e devolve as tags <link>/<script> finais.
 */
import { defineConfig } from 'vite';
import { resolve } from 'node:path';
import { writeFileSync, unlinkSync, existsSync } from 'node:fs';

const HOT_FILE = resolve(__dirname, 'public/hot');

/**
 * Plugin custom: cria/apaga o arquivo `public/hot` durante o ciclo de vida
 * do dev server. O helper PHP usa esse arquivo como sinal de "modo dev".
 */
function hotFilePlugin() {
    return {
        name: 'erp-vite-hot-file',
        configureServer(server) {
            server.httpServer?.once('listening', () => {
                const addr = server.httpServer.address();
                const port = typeof addr === 'object' ? addr.port : 5173;
                const host = server.config.server.host || 'localhost';
                writeFileSync(HOT_FILE, `http://${host}:${port}`);
                console.log(`\n  ✓ public/hot escrito → http://${host}:${port}\n`);
            });

            const cleanup = () => {
                if (existsSync(HOT_FILE)) {
                    try { unlinkSync(HOT_FILE); } catch {}
                }
            };
            process.on('SIGINT',  () => { cleanup(); process.exit(); });
            process.on('SIGTERM', () => { cleanup(); process.exit(); });
            process.on('exit',    cleanup);
        },
    };
}

export default defineConfig({
    base: '/build/',

    plugins: [hotFilePlugin()],

    resolve: {
        alias: {
            '~bootstrap': resolve(__dirname, 'node_modules/bootstrap'),
            '@scss':      resolve(__dirname, 'resources/scss'),
            '@js':        resolve(__dirname, 'resources/js'),
        },
    },

    css: {
        preprocessorOptions: {
            scss: {
                api: 'modern-compiler',
                quietDeps: true,
                silenceDeprecations: ['mixed-decls', 'color-functions', 'global-builtin', 'import'],
            },
        },
    },

    build: {
        outDir:      'public/build',
        emptyOutDir: true,
        manifest:    true,         // → public/build/.vite/manifest.json
        assetsDir:   'assets',
        sourcemap:   false,
        cssCodeSplit: true,
        rollupOptions: {
            input: {
                app:    resolve(__dirname, 'resources/js/app.js'),
                styles: resolve(__dirname, 'resources/scss/app.scss'),
            },
            output: {
                // Chunks separados pra plugins pesados — evita um bundle gigante.
                manualChunks: {
                    'vendor-bootstrap':    ['bootstrap', '@popperjs/core'],
                    'vendor-datatables':   ['simple-datatables'],
                    'vendor-quill':        ['quill'],
                    'vendor-fullcalendar': [
                        '@fullcalendar/core',
                        '@fullcalendar/daygrid',
                        '@fullcalendar/timegrid',
                        '@fullcalendar/interaction',
                    ],
                    'vendor-chart':        ['chart.js'],
                    'vendor-filepond':     ['filepond'],
                    'vendor-uppy':         ['@uppy/core', '@uppy/dashboard', '@uppy/xhr-upload'],
                },
            },
        },
    },

    server: {
        host:       '127.0.0.1',
        port:       5173,
        strictPort: true,
        cors:       true,
        hmr: {
            host: 'localhost',
        },
    },
});
