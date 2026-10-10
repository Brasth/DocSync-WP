/**
 * Journey 2 UI tests against the production hooks and SourceTabs.
 *
 *   node scripts/test-journey-2.mjs --ui-only
 *   node scripts/test-journey-2.mjs
 *
 * --ui-only runs the React 18 renderer suite (UI + crop) and skips all PHP.
 * Without that flag the host runner executes import + Google harnesses with
 * WP-CLI in the devcontainer WordPress service, and the matching harness twice
 * with php (normal and disable_functions=mb_strtolower). Inside that container
 * the same commands run directly. A missing file, a non-zero status, a FAIL
 * marker, a WARNING on protected Google tokens/options, or a missing/non-zero
 * PASS N FAIL N / failures counter fails the run.
 */
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const testEntry = path.join(root, 'tests', 'journey-2-ui.test.ts');
const cropEntry = path.join(root, 'tests', 'journey-2-pdf-crop.test.ts');
const containerPluginRoot = '/var/www/html/wp-content/plugins/brasth-document-sync-for-google-docs';
const uiOnly = process.argv.includes('--ui-only');

const phpSuites = [
  {
    label: 'tests/journey-2-tests.php',
    relative: path.join('tests', 'journey-2-tests.php'),
    runner: 'wpcli'
  },
  {
    label: 'tests/journey-2-google-tests.php',
    relative: path.join('tests', 'journey-2-google-tests.php'),
    runner: 'wpcli'
  },
  {
    label: 'tests/journey-2-matching-tests.php',
    relative: path.join('tests', 'journey-2-matching-tests.php'),
    runner: 'php',
    phpArgs: []
  },
  {
    label: 'tests/journey-2-matching-tests.php (disable mb_strtolower)',
    relative: path.join('tests', 'journey-2-matching-tests.php'),
    runner: 'php',
    phpArgs: ['-d', 'disable_functions=mb_strtolower']
  }
];

const projectRequire = createRequire(import.meta.url);
const viteRequire = createRequire(projectRequire.resolve('vite/package.json'));
const esbuild = viteRequire(viteRequire.resolve('esbuild'));
const reactExternals = new Set([
  'react',
  'react-test-renderer',
  'react/jsx-runtime',
  'react/jsx-dev-runtime'
]);

const setupSource = `
const queue = new Map();
let nextTimerId = 1;

function installWindow() {
  const memory = new Map();
  const location = {
    href: 'http://localhost/wp-admin/admin.php?page=brasth-document-sync-for-google-docs-sources',
    assign(url) {
      location.assigned = String(url);
    },
    assigned: ''
  };
  const history = {
    state: null,
    replaceState(state, _title, next) {
      history.state = state;
      location.href = new URL(String(next), location.href).toString();
    }
  };
  const sessionStorage = {
    getItem(key) {
      return memory.has(String(key)) ? memory.get(String(key)) : null;
    },
    setItem(key, value) {
      memory.set(String(key), String(value));
    },
    removeItem(key) {
      memory.delete(String(key));
    },
    clear() {
      memory.clear();
    }
  };
  const windowStub = {
    location,
    history,
    sessionStorage,
    setTimeout(fn) {
      const id = nextTimerId;
      nextTimerId += 1;
      queue.set(id, fn);
      return id;
    },
    clearTimeout(id) {
      queue.delete(id);
    },
    setInterval: globalThis.setInterval.bind(globalThis),
    clearInterval: globalThis.clearInterval.bind(globalThis),
    __flushJourneyTimers() {
      const fns = Array.from(queue.values());
      queue.clear();
      fns.forEach((fn) => fn());
    },
    __resetJourneyTimers() {
      queue.clear();
    }
  };
  globalThis.window = windowStub;
  globalThis.sessionStorage = sessionStorage;
  globalThis.document = {
    body: { appendChild() {} },
    createElement() {
      return {
        async: false,
        src: '',
        dataset: {},
        addEventListener() {},
        remove() {}
      };
    }
  };
}

installWindow();
`;

const elementSource = `
export {
  Children,
  Component,
  Fragment,
  PureComponent,
  StrictMode,
  Suspense,
  cloneElement,
  createContext,
  createElement,
  createRef,
  forwardRef,
  isValidElement,
  memo,
  startTransition,
  useCallback,
  useContext,
  useDebugValue,
  useDeferredValue,
  useEffect,
  useId,
  useImperativeHandle,
  useInsertionEffect,
  useLayoutEffect,
  useMemo,
  useReducer,
  useRef,
  useState,
  useSyncExternalStore,
  useTransition
} from 'react';
`;

const i18nSource = `
export function __(text) {
  return text;
}

export function _x(text) {
  return text;
}

export function _n(single, plural, count) {
  return Number(count) === 1 ? single : plural;
}

export function _nx(single, plural, count) {
  return Number(count) === 1 ? single : plural;
}

export function sprintf(format, ...args) {
  let auto = 0;
  return String(format).replace(/%(\\d+)\\$([sd])|%([sd])/g, (_match, index, indexedType, autoType) => {
    const type = indexedType || autoType;
    const value = indexedType ? args[Number(index) - 1] : args[auto++];
    if (type === 'd') {
      return String(Number.parseInt(String(value), 10));
    }
    return String(value);
  });
}

export function isRTL() {
  return false;
}
`;

const a11ySource = `
export function speak() {}
export function setup() {}
`;

const apiFetchSource = `
export default function apiFetch() {
  return Promise.reject(new Error('real HTTP is disabled in journey UI tests'));
}
`;

const journeyApiSource = `
function bag() {
  if (!globalThis.__journey2Mocks) {
    throw new Error('journey mocks are not installed');
  }
  return globalThis.__journey2Mocks;
}

function invoke(name, args) {
  const mocks = bag();
  mocks.calls.push({ name, args });
  const fn = mocks.impl[name];
  if (typeof fn !== 'function') {
    throw new Error('journey mock missing ' + name);
  }
  return fn.apply(null, args);
}

export function createIdempotencyKey() {
  const mocks = bag();
  mocks.calls.push({ name: 'createIdempotencyKey', args: [] });
  if (typeof mocks.impl.createIdempotencyKey === 'function') {
    return mocks.impl.createIdempotencyKey();
  }
  return crypto.randomUUID();
}

export function importAssetUrl(sessionId, fileId, assetId) {
  const mocks = bag();
  mocks.calls.push({ name: 'importAssetUrl', args: [sessionId, fileId, assetId] });
  if (typeof mocks.impl.importAssetUrl === 'function') {
    return mocks.impl.importAssetUrl(sessionId, fileId, assetId);
  }
  return 'https://example.test/assets/' + assetId;
}

export const attachExistingSource = (...args) => invoke('attachExistingSource', args);
export const cancelImportSession = (...args) => invoke('cancelImportSession', args);
export const commitImportSession = (...args) => invoke('commitImportSession', args);
export const createImportSession = (...args) => invoke('createImportSession', args);
export const createSourceBatch = (...args) => invoke('createSourceBatch', args);
export const getContinuationAuthUrl = (...args) => invoke('getContinuationAuthUrl', args);
export const getImportFilePreview = (...args) => invoke('getImportFilePreview', args);
export const getImportSession = (...args) => invoke('getImportSession', args);
export const getJourneyGoogleAccount = (...args) => invoke('getJourneyGoogleAccount', args);
export const listImportSessions = (...args) => invoke('listImportSessions', args);
export const searchJourneyDriveItems = (...args) => invoke('searchJourneyDriveItems', args);
export const storeRenderedImportAsset = (...args) => invoke('storeRenderedImportAsset', args);
export const updateImportFileOptions = (...args) => invoke('updateImportFileOptions', args);
export const uploadImportFiles = (...args) => invoke('uploadImportFiles', args);
`;

const adminApiSource = `
function invoke(name, args) {
  const mocks = globalThis.__journey2Mocks;
  if (!mocks) {
    throw new Error('journey mocks are not installed');
  }
  mocks.calls.push({ name, args });
  const fn = mocks.impl[name];
  if (typeof fn !== 'function') {
    throw new Error('journey mock missing ' + name);
  }
  return fn.apply(null, args);
}

export const inspectDocument = (...args) => invoke('inspectDocument', args);
export const listSharedDrives = (...args) => invoke('listSharedDrives', args);
`;

const radixSource = `
import { createElement } from 'react';

export const Root = ({ open, children }) => (open ? children : null);
export const Portal = ({ children }) => children;
export const Overlay = () => null;
export const Trigger = ({ children }) => children;
export const Title = ({ asChild, children }) => (asChild ? children : createElement('h2', null, children));
export const Description = ({ asChild, children }) => (asChild ? children : createElement('p', null, children));
export const Close = ({ asChild, children }) => (asChild ? children : createElement('button', { type: 'button' }, children));
export const Content = ({ children, className, ...props }) => createElement('div', { className, 'aria-busy': props['aria-busy'] }, children);
`;

const virtualFiles = {
  'virtual:journey-setup': setupSource,
  'virtual:wordpress-element': elementSource,
  'virtual:wordpress-i18n': i18nSource,
  'virtual:wordpress-a11y': a11ySource,
  'virtual:wordpress-api-fetch': apiFetchSource,
  'virtual:journey-api': journeyApiSource,
  'virtual:admin-api': adminApiSource,
  'virtual:radix-dialog': radixSource,
  'virtual:connect-first': 'export function ConnectFirst() { return null; }\n',
  'virtual:deck-preview': 'export function DeckPreview() { return null; }\nexport function deckHeaderSummary() { return ""; }\n',
  'virtual:google-picker': 'export function GooglePicker() { return null; }\n',
  'virtual:import-preview': 'export function ImportPreviewScreen() { return null; }\n',
  'virtual:upload-files': 'export function UploadFiles() { return null; }\n',
  'virtual:confirm-dialog': 'export function ConfirmDialog() { return null; }\n',
  'virtual:lazy-style': 'export function ensureLazyStyle() {}\n'
};

const dialogStubs = {
  './connect-first': 'virtual:connect-first',
  './deck-preview': 'virtual:deck-preview',
  './google-picker': 'virtual:google-picker',
  './import-preview': 'virtual:import-preview',
  './upload-files': 'virtual:upload-files',
  '../../shared/ui/confirm-dialog': 'virtual:confirm-dialog',
  '../doc-source-modal/lazy-drive-browser-panel': 'virtual:lazy-style'
};

const packages = {
  '@wordpress/element': 'virtual:wordpress-element',
  '@wordpress/i18n': 'virtual:wordpress-i18n',
  '@wordpress/a11y': 'virtual:wordpress-a11y',
  '@wordpress/api-fetch': 'virtual:wordpress-api-fetch',
  '@radix-ui/react-dialog': 'virtual:radix-dialog'
};

const plugin = {
  name: 'journey-2-ui-mocks',
  setup(build) {
    build.onResolve({ filter: /.*/ }, (args) => {
      if (reactExternals.has(args.path)) {
        return { path: projectRequire.resolve(args.path), external: true };
      }

      if (packages[args.path]) {
        return { path: packages[args.path], namespace: 'journey-mock' };
      }

      if (args.path === 'virtual:journey-setup') {
        return { path: args.path, namespace: 'journey-mock' };
      }

      if (/(?:^|\/)journey-api$/.test(args.path)) {
        return { path: 'virtual:journey-api', namespace: 'journey-mock' };
      }

      if (args.path === '../../api' && args.importer.endsWith(`${path.sep}use-add-content.ts`)) {
        return { path: 'virtual:admin-api', namespace: 'journey-mock' };
      }

      if (args.importer.endsWith(`${path.sep}add-content-dialog.tsx`) && dialogStubs[args.path]) {
        return { path: dialogStubs[args.path], namespace: 'journey-mock' };
      }

      return null;
    });

    build.onLoad({ filter: /.*/, namespace: 'journey-mock' }, (args) => {
      const contents = virtualFiles[args.path];

      if (!contents) {
        return { errors: [{ text: `Missing test mock ${args.path}` }] };
      }

      return { contents, loader: 'js', resolveDir: root };
    });

    build.onLoad({ filter: /journey-2-(?:ui|pdf-crop)\.test\.ts$/ }, async (args) => {
      const source = await readFile(args.path, 'utf8');
      return {
        contents: `import 'virtual:journey-setup';\n${source}`,
        loader: 'ts',
        resolveDir: path.dirname(args.path)
      };
    });

    build.onLoad({ filter: /add-content-dialog\.tsx$/ }, async (args) => {
      let contents = await readFile(args.path, 'utf8');

      if (!contents.includes('export { SourceTabs }')) {
        contents += '\nexport { SourceTabs };\n';
      }

      return { contents, loader: 'tsx', resolveDir: path.dirname(args.path) };
    });
  }
};

function phpFailure(output, status) {
  const failMarkers = output.match(/^(?:FAIL|FAILED)\b/gm)?.length ?? 0;
  const passFailMatch = output.match(/\bPASS\s+(\d+)\s+FAIL\s+(\d+)\b/);
  const failuresColon = output.match(/\bFAILURES:\s*(\d+)\b/i);
  const failuresWord = output.match(/\b(\d+)\s+failures\b/i);
  const failedColon = output.match(/\b(?:failed|failures)\s*[:=]\s*(\d+)/i);
  const allPass = /\bALL PASS\b/.test(output);
  let failCount = 0;
  let hasCounter = false;

  if (passFailMatch) {
    hasCounter = true;
    failCount = Number(passFailMatch[2]);
  } else if (failuresColon) {
    hasCounter = true;
    failCount = Number(failuresColon[1]);
  } else if (failuresWord) {
    hasCounter = true;
    failCount = Number(failuresWord[1]);
  } else if (failedColon) {
    hasCounter = true;
    failCount = Number(failedColon[1]);
  } else if (allPass) {
    hasCounter = true;
    failCount = 0;
  }

  const warningFail = /WARNING:\s+(?:token rows changed|.* changed in DB)/i.test(output);
  const marked = /FAILURES!|ERRORS!|There (?:was|were) \d+ (?:failure|error)/i.test(output);
  const failed =
    status !== 0 || failMarkers > 0 || failCount > 0 || marked || warningFail || !hasCounter;

  return { failed, failMarkers, failCount, hasCounter, warningFail };
}

function runningInsideWordPressContainer() {
  if (root === containerPluginRoot) {
    return true;
  }

  return existsSync('/.dockerenv') && existsSync('/usr/local/bin/wp') && Boolean(process.env.WORDPRESS_DB_HOST);
}

function dockerComposeInvocation(insideContainer) {
  const composeFile = path.join(root, '.devcontainer', 'docker-compose.yml');
  const composePlugin = insideContainer
    ? null
    : spawnSync('docker', ['compose', 'version'], { encoding: 'utf8' });
  const command = insideContainer || (composePlugin?.status ?? 1) === 0 ? (insideContainer ? null : 'docker') : 'docker-compose';

  return { composeFile, command };
}

function runSpawned(command, args, label) {
  const result = spawnSync(command, args, { cwd: root, encoding: 'utf8' });
  const output = `${result.stdout ?? ''}${result.stderr ?? ''}`;

  if (result.stdout) {
    process.stdout.write(result.stdout);
  }

  if (result.stderr) {
    process.stderr.write(result.stderr);
  }

  const status = result.status ?? 1;
  const failure = phpFailure(output, status);
  const failed = failure.failed || result.error != null;
  console.error(
    `PHP ${label}: ${failed ? 'failed' : 'passed'} (exit ${status}, fail ${failure.failMarkers + failure.failCount}${failure.hasCounter ? '' : ', missing counter'})`
  );
  return { failed };
}

function runWpCliSuite(suite) {
  const phpFile = path.join(root, suite.relative);
  const relative = path.relative(root, phpFile);

  if (relative.startsWith('..') || path.isAbsolute(relative)) {
    console.error(`PHP ${suite.label}: failed (refused path)`);
    return { failed: true };
  }

  if (!existsSync(phpFile)) {
    console.error(`PHP ${suite.label}: failed (missing ${suite.label})`);
    return { failed: true };
  }

  const insideContainer = runningInsideWordPressContainer();
  const evalFile = insideContainer ? phpFile : `${containerPluginRoot}/${suite.relative.replace(/\\/g, '/')}`;
  const { composeFile, command } = dockerComposeInvocation(insideContainer);
  const wpArgs = ['exec', '-T', 'wordpress', 'wp', '--allow-root', 'eval-file', evalFile];

  if (insideContainer) {
    return runSpawned('wp', ['--allow-root', 'eval-file', evalFile], suite.label);
  }

  const args =
    command === 'docker'
      ? ['compose', '-f', composeFile, ...wpArgs]
      : ['-f', composeFile, ...wpArgs];

  return runSpawned(command, args, suite.label);
}

function runPhpCliSuite(suite) {
  const phpFile = path.join(root, suite.relative);
  const relative = path.relative(root, phpFile);

  if (relative.startsWith('..') || path.isAbsolute(relative)) {
    console.error(`PHP ${suite.label}: failed (refused path)`);
    return { failed: true };
  }

  if (!existsSync(phpFile)) {
    console.error(`PHP ${suite.label}: failed (missing ${suite.label})`);
    return { failed: true };
  }

  const insideContainer = runningInsideWordPressContainer();
  const scriptPath = insideContainer ? phpFile : `${containerPluginRoot}/${suite.relative.replace(/\\/g, '/')}`;
  const pluginDir = insideContainer ? root : containerPluginRoot;
  const phpArgs = [...(suite.phpArgs ?? []), scriptPath, pluginDir];
  const { composeFile, command } = dockerComposeInvocation(insideContainer);

  if (insideContainer) {
    return runSpawned('php', phpArgs, suite.label);
  }

  const execArgs = ['exec', '-T', 'wordpress', 'php', ...phpArgs];
  const args =
    command === 'docker'
      ? ['compose', '-f', composeFile, ...execArgs]
      : ['-f', composeFile, ...execArgs];

  return runSpawned(command, args, suite.label);
}

function runAllPhp() {
  let failed = false;

  for (const suite of phpSuites) {
    const result = suite.runner === 'wpcli' ? runWpCliSuite(suite) : runPhpCliSuite(suite);

    if (result.failed) {
      failed = true;
    }
  }

  return { failed };
}

const workDir = mkdtempSync(path.join(tmpdir(), 'journey-2-ui-'));
let exitCode = 0;

try {
  const entries = [testEntry];

  if (existsSync(cropEntry)) {
    entries.push(cropEntry);
  }

  const outfiles = [];

  for (const entry of entries) {
    const outfile = path.join(workDir, `${path.basename(entry, '.ts')}.mjs`);

    try {
      await esbuild.build({
        absWorkingDir: root,
        entryPoints: [entry],
        outfile,
        bundle: true,
        platform: 'node',
        format: 'esm',
        jsx: 'transform',
        jsxFactory: 'createElement',
        jsxFragment: 'Fragment',
        sourcemap: 'inline',
        plugins: [plugin],
        logLevel: 'silent'
      });
      outfiles.push(outfile);
    } catch (error) {
      const errors = error?.errors ?? [];
      console.error(errors.length > 0 ? esbuild.formatMessagesSync(errors, { kind: 'error', color: false }).join('\n') : error);
      exitCode = 1;
      break;
    }
  }

  if (exitCode === 0) {
    const ui = spawnSync(process.execPath, ['--enable-source-maps', '--test', ...outfiles], {
      cwd: root,
      stdio: 'inherit'
    });

    if ((ui.status ?? 1) !== 0) {
      exitCode = 1;
    }
  }

  if (uiOnly) {
    for (const suite of phpSuites) {
      console.error(`PHP ${suite.label}: skipped (--ui-only)`);
    }
  } else if (runAllPhp().failed) {
    exitCode = 1;
  }
} finally {
  rmSync(workDir, { recursive: true, force: true });
}

if (exitCode !== 0) {
  process.exit(exitCode);
}
