#!/usr/bin/env node
/**
 * Lint script: verifies the == Screenshots == section of readme.txt
 * stays in sync with assets/screenshot-N.png files.
 *
 * Rules:
 *   - readme.txt must contain a == Screenshots == section.
 *   - Each line inside that section is `N. Caption` where N starts at 1
 *     and increases by 1 with no gaps.
 *   - For every numbered entry, assets/screenshot-N.png must exist.
 *   - No extra screenshot-N.png files outside the 1..N range.
 *
 * Exits non-zero on any drift so CI can fail the build.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const repoRoot = process.cwd();
const readmePath = join(repoRoot, 'readme.txt');
const assetsDir = join(repoRoot, 'assets');

function fail(messages) {
	console.error('Screenshot lint failed:');
	for (const message of messages) {
		console.error(`  - ${message}`);
	}
	process.exit(1);
}

function readScreenshotsBlock(text) {
	const headerRegex = /^== Screenshots ==[ \t]*$/m;
	const headerMatch = text.match(headerRegex);
	if (!headerMatch) {
		return { found: false };
	}

	const start = headerMatch.index + headerMatch[0].length;
	const rest = text.slice(start);
	const nextHeader = rest.match(/^== [^=].* ==[ \t]*$/m);
	const blockEnd = nextHeader ? start + nextHeader.index : text.length;
	const block = text.slice(start, blockEnd);

	const entries = [];
	const seen = new Set();
	for (const line of block.split(/\r?\n/)) {
		const match = line.match(/^\s*(\d+)\.\s+(.+?)\s*$/);
		if (!match) {
			continue;
		}
		const num = Number.parseInt(match[1], 10);
		if (seen.has(num)) {
			fail([`Duplicate screenshot number ${num} in readme.txt Screenshots section.`]);
		}
		seen.add(num);
		entries.push({ num, caption: match[2] });
	}

	return { found: true, entries };
}

function check() {
	let text;
	try {
		text = readFileSync(readmePath, 'utf8');
	} catch {
		fail([`Cannot read ${resolve(readmePath)}.`]);
	}

	const block = readScreenshotsBlock(text);
	if (!block.found) {
		fail(['readme.txt is missing a == Screenshots == section.']);
	}

	const entries = block.entries;
	const expected = entries.length;
	const numbers = entries.map((entry) => entry.num);

	for (let i = 0; i < numbers.length; i += 1) {
		if (numbers[i] !== i + 1) {
			fail([
				`Screenshots list must be numbered sequentially starting at 1. ` +
					`Position ${i + 1} has number ${numbers[i]}.`,
			]);
		}
	}

	let assetFiles;
	try {
		assetFiles = readdirSync(assetsDir);
	} catch {
		fail([`Cannot read assets directory: ${resolve(assetsDir)}.`]);
	}

	const screenshotPattern = /^screenshot-(\d+)\.(png|jpg)$/i;
	const fileNumbers = [];
	for (const file of assetFiles) {
		const match = file.match(screenshotPattern);
		if (!match) {
			continue;
		}
		fileNumbers.push({ name: file, num: Number.parseInt(match[1], 10) });
	}
	fileNumbers.sort((a, b) => a.num - b.num);

	const errors = [];
	if (fileNumbers.length !== expected) {
		errors.push(
			`Screenshot count mismatch: readme.txt lists ${expected} entries, ` +
				`assets/ contains ${fileNumbers.length} screenshot files.`,
		);
	}

	for (let i = 1; i <= expected; i += 1) {
		const expectedName = `screenshot-${i}.png`;
		if (!fileNumbers.some((file) => file.name === expectedName)) {
			errors.push(`Missing asset file: assets/${expectedName}`);
		}
	}

	for (const file of fileNumbers) {
		if (file.num < 1 || file.num > expected) {
			errors.push(
				`assets/${file.name} has no matching entry in readme.txt Screenshots section.`,
			);
		}
	}

	if (errors.length > 0) {
		fail(errors);
	}

	console.log(
		`Screenshot lint OK: ${expected} entries match ${fileNumbers.length} files.`,
	);
}

check();
