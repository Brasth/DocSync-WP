#!/usr/bin/env node
/**
 * Builds WordPress JavaScript translation JSON files from reviewed PO files.
 */

import { existsSync, readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const languagesDir = 'languages';

if (!existsSync(languagesDir)) {
	console.log('No languages directory found; skipping JS translation JSON generation.');
	process.exit(0);
}

const localeFiles = readdirSync(languagesDir).filter((file) => file.endsWith('.po'));

if (localeFiles.length === 0) {
	console.log('No locale .po files found; skipping JS translation JSON generation.');
	process.exit(0);
}

const result = spawnSync('wp', ['i18n', 'make-json', languagesDir, '--no-purge'], {
	stdio: 'inherit',
});

if (result.error) {
	console.error('WP-CLI is required to generate JS translation JSON files when .po files exist.');
	process.exit(1);
}

process.exit(result.status ?? 1);
