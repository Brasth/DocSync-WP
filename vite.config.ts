import { resolve } from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

const entryByMode: Record<string, string> = {
  admin: 'resources/js/admin/main.tsx',
  'post-sync': 'resources/js/admin/post-sync-entry.tsx'
};

const wordpressReactExternals = new Set(['@wordpress/element', 'react', 'react-dom']);

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
        external: (id) => wordpressReactExternals.has(id),
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
            '@wordpress/element': 'wp.element',
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
