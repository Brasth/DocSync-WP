#!/usr/bin/env node
/**
 * Verify Drive Folders ops helpers: attention, filters, primary action, status tone.
 */

import {
	attentionRank,
	attentionReason,
	filterFolderWatches,
	primaryWatchAction,
	watchNeedsAttention,
	watchStatusTone
} from '../resources/js/admin/features/folder-watches/folder-watch-ops.ts';

const fail = (message) => {
	process.stderr.write(`${message}\n`);
	process.exit(1);
};

const assertSame = (expected, actual, message) => {
	if (JSON.stringify(expected) !== JSON.stringify(actual)) {
		fail(`${message} expected ${JSON.stringify(expected)} got ${JSON.stringify(actual)}`);
	}
};

const watch = (overrides) => ({
	id: overrides.id || 'w1',
	folderName: overrides.folderName || 'Client A',
	status: overrides.status || 'watching',
	pendingCount: overrides.pendingCount || 0,
	importedCount: overrides.importedCount || 0,
	failed: overrides.failed || [],
	lastError: overrides.lastError || '',
	ownerDisplayName: overrides.ownerDisplayName || 'Ada'
});

const healthy = watch({ id: 'healthy', folderName: 'Acme blog', status: 'watching', importedCount: 4 });
const importing = watch({ id: 'importing', folderName: 'Beta site', status: 'importing', pendingCount: 2 });
const paused = watch({ id: 'paused', folderName: 'Paused client', status: 'paused' });
const errored = watch({ id: 'error', folderName: 'Broken client', status: 'error', lastError: 'Drive quota' });
const failedDocs = watch({
	id: 'failed',
	folderName: 'Partial client',
	status: 'watching',
	importedCount: 3,
	ownerDisplayName: 'Bea',
	failed: [{ fileId: 'doc-1', name: 'Brief', code: 'sync_failed', message: 'timeout' }]
});

assertSame(false, watchNeedsAttention(healthy), 'healthy watch needs attention');
assertSame(true, watchNeedsAttention(importing), 'importing watch needs attention');
assertSame(true, watchNeedsAttention(paused), 'paused watch needs attention');
assertSame(true, watchNeedsAttention(errored), 'error watch needs attention');
assertSame(true, watchNeedsAttention(failedDocs), 'failed-doc watch needs attention');

assertSame(0, attentionRank(errored), 'error rank');
assertSame(0, attentionRank(failedDocs), 'failed-doc rank');
assertSame(1, attentionRank(importing), 'importing rank');
assertSame(2, attentionRank(paused), 'paused rank');
assertSame(3, attentionRank(healthy), 'healthy rank');

assertSame('watching', watchStatusTone('watching'), 'watching tone');
assertSame('importing', watchStatusTone('importing'), 'importing tone');
assertSame('paused', watchStatusTone('paused'), 'paused tone');
assertSame('error', watchStatusTone('error'), 'error tone');

assertSame(null, attentionReason(healthy), 'healthy reason');
assertSame('importing', attentionReason(importing), 'importing reason');
assertSame('paused', attentionReason(paused), 'paused reason');
assertSame('error', attentionReason(errored), 'error reason');
assertSame('failed', attentionReason(failedDocs), 'failed-doc reason');

assertSame('scan', primaryWatchAction(healthy), 'healthy primary');
assertSame('manage', primaryWatchAction(importing), 'importing primary');
assertSame('resume', primaryWatchAction(paused), 'paused primary');
assertSame('fix', primaryWatchAction(errored), 'error primary');
assertSame('fix', primaryWatchAction(failedDocs), 'failed-doc primary');

const all = [healthy, importing, paused, errored, failedDocs];
assertSame(
	['error', 'failed', 'importing', 'paused'],
	filterFolderWatches(all, '', 'attention').map((item) => item.id),
	'attention filter order'
);
assertSame(
	['importing'],
	filterFolderWatches(all, '', 'importing').map((item) => item.id),
	'importing filter'
);
assertSame(
	['healthy', 'failed'],
	filterFolderWatches(all, '', 'watching').map((item) => item.id),
	'watching filter keeps failed-but-watching'
);
assertSame(
	['paused'],
	filterFolderWatches(all, 'paused', '').map((item) => item.id),
	'search by folder name'
);
assertSame(
	['healthy'],
	filterFolderWatches(all, 'ada', 'watching').map((item) => item.id),
	'search by owner with watching filter'
);

process.stdout.write('Folder watch ops verifier OK.\n');
