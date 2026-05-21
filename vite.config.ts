import { resolve } from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

const entryByMode: Record<string, string> = {
  admin: 'resources/js/admin/entries/admin-entry.tsx',
  'post-sync': 'resources/js/admin/entries/post-sync-entry.tsx'
};

const wordpressExternals = new Set([
  '@wordpress/a11y',
  '@wordpress/api-fetch',
  '@wordpress/components',
  '@wordpress/element',
  '@wordpress/i18n',
  '@wordpress/url',
  'react',
  'react-dom'
]);

export default defineConfig(({ mode }) => {
  const entryName = mode === 'post-sync' ? 'postSync' : 'admin';
  const entryPath = entryByMode[mode] ?? entryByMode.admin;

  return {
    plugins: [tailwindcss()],
    publicDir: false,
    resolve: {
      alias: {
        // Radix packages use the automatic JSX runtime; keep it on WordPress React.
        'react/jsx-dev-runtime': resolve(__dirname, 'resources/js/admin/wordpress-jsx-runtime.ts'),
        'react/jsx-runtime': resolve(__dirname, 'resources/js/admin/wordpress-jsx-runtime.ts')
      }
    },
    esbuild: {
      jsx: 'transform',
      jsxFactory: 'createElement',
      jsxFragment: 'Fragment'
    },
    build: {
      outDir: 'build',
      emptyOutDir: mode !== 'post-sync',
      cssCodeSplit: false,
      manifest: mode === 'post-sync' ? 'manifest.post-sync.json' : 'manifest.json',
      modulePreload: false,
      sourcemap: true,
      rollupOptions: {
        input: {
          [entryName]: resolve(__dirname, entryPath)
        },
        external: (id) => wordpressExternals.has(id),
        onwarn(warning, warn) {
          if (
            warning.code === 'MODULE_LEVEL_DIRECTIVE'
            && typeof warning.id === 'string'
            && warning.id.includes('/@radix-ui/')
          ) {
            return;
          }

          warn(warning);
        },
        output: {
          format: 'iife',
          name: entryName === 'postSync' ? 'DocSyncWPPostSyncBundle' : 'DocSyncWPAdminBundle',
          globals: {
            '@wordpress/a11y': 'wp.a11y',
            '@wordpress/api-fetch': 'wp.apiFetch',
            '@wordpress/components': 'wp.components',
            '@wordpress/element': 'wp.element',
            '@wordpress/i18n': 'wp.i18n',
            '@wordpress/url': 'wp.url',
            react: 'wp.element',
            'react-dom': 'wp.element'
          },
          entryFileNames: 'assets/js/[name].[hash].js',
          chunkFileNames: 'assets/js/[name].[hash].js',
          assetFileNames: 'assets/[ext]/[name].[hash][extname]'
        }
      }
    }
  };
});
