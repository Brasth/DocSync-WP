import { copyFileSync, mkdirSync, readdirSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig, type Plugin } from 'vite';

const entryByMode: Record<string, string> = {
  setup: 'resources/js/admin/entries/setup-entry.tsx',
  sources: 'resources/js/admin/entries/sources-entry.tsx',
  folders: 'resources/js/admin/entries/folders-entry.tsx',
  logs: 'resources/js/admin/entries/logs-entry.tsx',
  'post-sync': 'resources/js/admin/entries/post-sync-entry.tsx',
  'doc-source-modal': 'resources/js/admin/entries/doc-source-modal-entry.ts',
  'drive-browser': 'resources/js/admin/entries/drive-browser-entry.tsx',
  // Network-lazy PDF.js bundle: never enqueued, injected only when a PDF preview mounts.
  'pdf-renderer': 'resources/js/admin/features/add-content/pdf-renderer.ts'
};

const entryNameByMode: Record<string, string> = {
  setup: 'setup',
  sources: 'sources',
  folders: 'folders',
  logs: 'logs',
  'post-sync': 'postSync',
  'doc-source-modal': 'docSourceModal',
  'drive-browser': 'driveBrowser',
  'pdf-renderer': 'pdfRenderer'
};

const PDF_RENDERER_MODE = 'pdf-renderer';

/**
 * Recursively copy a directory file by file.
 *
 * `cpSync` is avoided on purpose: Node 24 copies directories natively through
 * `std::filesystem`, which fails with EACCES on virtiofs bind mounts (the Docker
 * dev container) and leaves write-only partial files behind.
 */
const copyDirectory = (sourceDir: string, targetDir: string): void => {
  mkdirSync(targetDir, { recursive: true });

  for (const entry of readdirSync(sourceDir, { withFileTypes: true })) {
    const sourcePath = join(sourceDir, entry.name);
    const targetPath = join(targetDir, entry.name);

    if (entry.isDirectory()) {
      copyDirectory(sourcePath, targetPath);
    } else if (entry.isFile()) {
      copyFileSync(sourcePath, targetPath);
    }
  }
};

/**
 * Copy the PDF.js runtime data the renderer loads by URL (CMaps, standard fonts,
 * wasm decoders) with their license files into `build/assets/pdf/`, so every
 * PDF.js request stays on this site. The target is regenerated on each build so
 * repeat builds never mix in stale or partially copied files.
 */
const copyPdfJsAssets = (): Plugin => ({
  name: 'docsync-wp-copy-pdfjs-assets',
  apply: 'build',
  writeBundle() {
    const packageDir = dirname(createRequire(import.meta.url).resolve('pdfjs-dist/package.json'));
    const targetDir = resolve(__dirname, 'build/assets/pdf');

    rmSync(targetDir, { recursive: true, force: true });
    mkdirSync(targetDir, { recursive: true });

    for (const directory of ['cmaps', 'standard_fonts', 'wasm']) {
      copyDirectory(resolve(packageDir, directory), resolve(targetDir, directory));
    }

    copyFileSync(resolve(packageDir, 'LICENSE'), resolve(targetDir, 'LICENSE'));
  }
});

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
  const entryName = entryNameByMode[mode] ?? entryNameByMode.setup;
  const entryPath = entryByMode[mode] ?? entryByMode.setup;

  return {
    plugins: mode === PDF_RENDERER_MODE ? [copyPdfJsAssets()] : [tailwindcss()],
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
      emptyOutDir: mode === 'setup',
      // PDF.js uses private class members; downleveling them adds esbuild helpers outside the IIFE wrapper as globals.
      ...(mode === PDF_RENDERER_MODE ? { target: 'es2022' } : {}),
      cssCodeSplit: false,
      manifest: `manifest.${mode}.json`,
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
          name: `DocSyncWP${entryName.charAt(0).toUpperCase()}${entryName.slice(1)}Bundle`,
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
          // The PDF.js worker (`?url` import of an .mjs file) is emitted as hashed .js so hosts serve a JavaScript MIME type.
          assetFileNames: (assetInfo) => (assetInfo.names.some((name) => name.endsWith('.mjs'))
            ? 'assets/js/[name].[hash].js'
            : 'assets/[ext]/[name].[hash][extname]')
        }
      }
    }
  };
});
