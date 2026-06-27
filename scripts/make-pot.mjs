#!/usr/bin/env node
/**
 * Extracts WordPress gettext strings from PHP and admin TypeScript sources.
 */

import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, extname, join, relative } from 'node:path';

const domain = 'brasth-document-sync-for-google-docs';
const outputFile = 'languages/brasth-document-sync-for-google-docs.pot';
const roots = ['brasth-document-sync-for-google-docs.php', 'src', 'resources/js'];
const extensions = new Set(['.php', '.ts', '.tsx']);
const callPattern = new RegExp(
	`\\b(?:__|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\\s*\\(\\s*(['"\`])((?:\\\\[\\s\\S]|(?!\\1)[\\s\\S])*?)\\1\\s*,\\s*(['"\`])${domain}\\3`,
	'g',
);

const entries = new Map();

function walk(path) {
	const stat = statSync(path);
	if (stat.isDirectory()) {
		return readdirSync(path).flatMap((file) => walk(join(path, file)));
	}

	return extensions.has(extname(path)) ? [path] : [];
}

function decodeLiteral(value) {
	return value
		.replace(/\\n/g, '\n')
		.replace(/\\r/g, '\r')
		.replace(/\\t/g, '\t')
		.replace(/\\'/g, "'")
		.replace(/\\"/g, '"')
		.replace(/\\\\/g, '\\');
}

function poEscape(value) {
	return value
		.replace(/\\/g, '\\\\')
		.replace(/"/g, '\\"')
		.replace(/\r/g, '\\r')
		.replace(/\n/g, '\\n');
}

function translatorCommentBefore(content, index) {
	const before = content.slice(0, index);
	const commentPattern = /\/\*\s*translators:\s*([\s\S]*?)\*\//gi;
	let match;

	while (true) {
		const next = commentPattern.exec(before);
		if (!next) {
			break;
		}
		match = next;
	}

	if (!match) {
		return '';
	}

	const between = before.slice(match.index + match[0].length);
	if (!/^[\s(),?:]*$/.test(between)) {
		return '';
	}

	return match[1]
		.split(/\r?\n/)
		.map((line) => line.replace(/^\s*\*\s?/, '').trim())
		.filter(Boolean)
		.join(' ');
}

function lineNumberAt(content, index) {
	return content.slice(0, index).split(/\r?\n/).length;
}

function addEntry(msgid, ref, comment) {
	if (!entries.has(msgid)) {
		entries.set(msgid, { comments: new Set(), refs: new Set() });
	}

	const entry = entries.get(msgid);
	entry.refs.add(ref);

	if (comment) {
		entry.comments.add(comment);
	}
}

for (const file of roots.flatMap((root) => walk(root))) {
	const content = readFileSync(file, 'utf8');
	const source = relative(process.cwd(), file);
	let match;

	while ((match = callPattern.exec(content)) !== null) {
		const msgid = decodeLiteral(match[2]);
		const ref = `${source}:${lineNumberAt(content, match.index)}`;
		addEntry(msgid, ref, translatorCommentBefore(content, match.index));
	}
}

const now = new Date().toISOString().replace(/\.\d{3}Z$/, '+0000');
const header = [
	'# Copyright (C) 2026 Brasth',
	'# This file is distributed under the GPLv2 or later.',
	'msgid ""',
	'msgstr ""',
	'"Project-Id-Version: Brasth Document Sync for Google Docs 1.0.8\\n"',
	`"POT-Creation-Date: ${now}\\n"`,
	'"MIME-Version: 1.0\\n"',
	'"Content-Type: text/plain; charset=UTF-8\\n"',
	'"Content-Transfer-Encoding: 8bit\\n"',
	`"X-Domain: ${domain}\\n"`,
	'',
];

const body = [...entries.entries()]
	.sort(([left], [right]) => left.localeCompare(right))
	.flatMap(([msgid, entry]) => [
		...[...entry.comments].map((comment) => `#. translators: ${comment}`),
		`#: ${[...entry.refs].sort().join(' ')}`,
		`msgid "${poEscape(msgid)}"`,
		'msgstr ""',
		'',
	]);

mkdirSync(dirname(outputFile), { recursive: true });
writeFileSync(outputFile, `${header.concat(body).join('\n')}\n`);

console.log(`Extracted ${entries.size} strings to ${outputFile}.`);
