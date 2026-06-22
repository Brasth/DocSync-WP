#!/usr/bin/env node
/**
 * Lint script: validates the WordPress Playground blueprint file.
 *
 * Rules:
 *   - assets/blueprints/blueprint.json must exist and be valid JSON.
 *   - The blueprint must define landingPage, preferredVersions, and steps.
 *   - preferredVersions must specify php and wp.
 *   - steps must be a non-empty array and each step must have a step name.
 *   - A login step must be present.
 *   - The plugin must be installed from wordpress.org/plugins with the correct slug.
 *
 * Exits non-zero on any validation failure so CI can fail the build.
 */

import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const repoRoot = process.cwd();
const blueprintPath = join(repoRoot, 'assets', 'blueprints', 'blueprint.json');
const pluginSlug = 'brasth-document-sync-for-google-docs';

function fail(messages) {
	console.error('Blueprint lint failed:');
	for (const message of messages) {
		console.error(`  - ${message}`);
	}
	process.exit(1);
}

function isNonEmptyString(value) {
	return typeof value === 'string' && value.trim() !== '';
}

function check() {
	let raw;
	try {
		raw = readFileSync(blueprintPath, 'utf8');
	} catch {
		fail([`Cannot read ${resolve(blueprintPath)}.`]);
	}

	let blueprint;
	try {
		blueprint = JSON.parse(raw);
	} catch (error) {
		fail([
			`Invalid JSON in ${resolve(blueprintPath)}: ${error instanceof Error ? error.message : String(error)}`,
		]);
	}

	const errors = [];

	if (!isNonEmptyString(blueprint.landingPage)) {
		errors.push('blueprint.landingPage must be a non-empty string.');
	}

	if (
		!blueprint.preferredVersions ||
		typeof blueprint.preferredVersions !== 'object' ||
		Array.isArray(blueprint.preferredVersions)
	) {
		errors.push('blueprint.preferredVersions must be an object.');
	} else {
		if (!isNonEmptyString(blueprint.preferredVersions.php)) {
			errors.push('blueprint.preferredVersions.php must be a non-empty string.');
		}
		if (!isNonEmptyString(blueprint.preferredVersions.wp)) {
			errors.push('blueprint.preferredVersions.wp must be a non-empty string.');
		}
	}

	if (!Array.isArray(blueprint.steps) || blueprint.steps.length === 0) {
		errors.push('blueprint.steps must be a non-empty array.');
	}

	if (Array.isArray(blueprint.steps)) {
		let hasLogin = false;
		let hasPluginInstall = false;
		let hasRunPhp = false;

		for (let i = 0; i < blueprint.steps.length; i += 1) {
			const step = blueprint.steps[i];

			if (!step || typeof step !== 'object' || Array.isArray(step)) {
				errors.push(`Step ${i + 1} must be an object.`);
				continue;
			}

			if (!isNonEmptyString(step.step)) {
				errors.push(`Step ${i + 1} is missing a non-empty "step" property.`);
				continue;
			}

			if (step.step === 'login') {
				hasLogin = true;
			}

			if (step.step === 'installPlugin') {
				const pluginZipFile = step.pluginZipFile;
				if (
					pluginZipFile &&
					typeof pluginZipFile === 'object' &&
					!Array.isArray(pluginZipFile) &&
					pluginZipFile.resource === 'wordpress.org/plugins' &&
					pluginZipFile.slug === pluginSlug
				) {
					hasPluginInstall = true;
				}
			}

			if (step.step === 'runPHP') {
				hasRunPhp = true;
				if (!isNonEmptyString(step.code)) {
					errors.push(`Step ${i + 1} (runPHP) must have a non-empty "code" property.`);
				}
			}
		}

		if (!hasLogin) {
			errors.push('Blueprint must include a login step.');
		}

		if (!hasPluginInstall) {
			errors.push(
				`Blueprint must include an installPlugin step that installs ${pluginSlug} from wordpress.org/plugins.`,
			);
		}

		if (!hasRunPhp) {
			console.warn(
				'Blueprint lint warning: no runPHP step found. Consider seeding demo content for the preview.',
			);
		}
	}

	if (errors.length > 0) {
		fail(errors);
	}

	console.log('Blueprint lint OK: assets/blueprints/blueprint.json is valid.');
}

check();
