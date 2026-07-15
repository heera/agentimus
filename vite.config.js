import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

/**
 * Builds the Vue admin app into assets/admin/ with stable filenames
 * (app.js / app.css) so PHP can enqueue them without reading a manifest.
 */
export default defineConfig({
  plugins: [vue()],
  // Emit ASCII-only output: any non-ASCII (a ✓ in CSS `content`, a · separator)
  // becomes a \XXXX escape, so the bundle can't mojibake if a host ever serves
  // app.css as latin-1 instead of UTF-8. Applies to the CSS minify pass too.
  esbuild: { charset: 'ascii' },
  build: {
    outDir: 'assets/admin',
    emptyOutDir: true,
    manifest: false,
    cssCodeSplit: false,
    rollupOptions: {
      input: fileURLToPath(new URL('./resources/admin/main.js', import.meta.url)),
      output: {
        // The admin bundle is enqueued as a CLASSIC script, so it must not leak
        // top-level bindings into the global scope — a minifier-named `wp`/`lodash`
        // etc. would collide with WordPress's own globals ("Identifier 'wp' has
        // already been declared"). IIFE wraps everything in a function scope.
        format: 'iife',
        entryFileNames: 'app.js',
        assetFileNames: (info) => {
          const name = info.name || info.names?.[0] || '';
          return name.endsWith('.css') ? 'app.css' : 'assets/[name][extname]';
        },
      },
    },
  },
});
