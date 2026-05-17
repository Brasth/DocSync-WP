import { resolve } from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [tailwindcss()],
  publicDir: false,
  esbuild: {
    jsx: 'transform',
    jsxFactory: 'createElement',
    jsxFragment: 'Fragment'
  },
  build: {
    outDir: 'build',
    emptyOutDir: true,
    cssCodeSplit: false,
    manifest: 'manifest.json',
    modulePreload: false,
    sourcemap: true,
    rollupOptions: {
      input: {
        admin: resolve(__dirname, 'resources/js/admin/main.tsx')
      },
      external: ['@wordpress/element'],
      output: {
        format: 'iife',
        name: 'DocSyncWPAdminBundle',
        globals: {
          '@wordpress/element': 'wp.element'
        },
        entryFileNames: 'assets/js/[name].[hash].js',
        chunkFileNames: 'assets/js/[name].[hash].js',
        assetFileNames: 'assets/[ext]/[name].[hash][extname]'
      }
    }
  }
});
