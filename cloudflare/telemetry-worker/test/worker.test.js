import assert from 'node:assert/strict';
import test from 'node:test';

import { cleanupOldSites, handleRequest } from '../src/index.js';

const now = new Date('2026-06-30T12:00:00.000Z');
const validPayload = {
  consentVersion: '2026-06-30',
  phpVersion: '8.3.1',
  pluginSlug: 'brasth-document-sync-for-google-docs',
  pluginVersion: '1.1.1',
  siteHash: 'a'.repeat(64),
  wpVersion: '6.6.2'
};

test('health returns ok', async () => {
  const response = await handleRequest(new Request('https://telemetry.example/health'), makeEnv(), undefined, now);

  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), { ok: true });
});

test('valid check-in inserts a site row', async () => {
  const env = makeEnv();
  const response = await handleRequest(checkInRequest(validPayload), env, undefined, now);

  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), { ok: true });
  assert.equal(env.DB.rows.size, 1);
  assert.deepEqual(env.DB.rows.get(validPayload.siteHash), {
    checkin_count: 1,
    consent_version: '2026-06-30',
    first_seen: now.toISOString(),
    last_seen: now.toISOString(),
    php_version: '8.3.1',
    plugin_slug: 'brasth-document-sync-for-google-docs',
    plugin_version: '1.1.1',
    site_hash: validPayload.siteHash,
    wp_version: '6.6.2'
  });
});

test('repeat check-in updates last_seen and increments count', async () => {
  const env = makeEnv();
  const nextWeek = new Date('2026-07-07T12:00:00.000Z');

  await handleRequest(checkInRequest(validPayload), env, undefined, now);
  await handleRequest(checkInRequest({ ...validPayload, pluginVersion: '1.1.2' }), env, undefined, nextWeek);

  const row = env.DB.rows.get(validPayload.siteHash);

  assert.equal(row.checkin_count, 2);
  assert.equal(row.first_seen, now.toISOString());
  assert.equal(row.last_seen, nextWeek.toISOString());
  assert.equal(row.plugin_version, '1.1.2');
});

test('invalid check-in payloads are rejected', async (t) => {
  const cases = [
    {
      body: { ...validPayload, siteHash: 'not-a-hash' },
      error: 'invalid_site_hash',
      name: 'bad hash'
    },
    {
      body: { ...validPayload, pluginSlug: 'other-plugin' },
      error: 'invalid_plugin_slug',
      name: 'bad slug'
    },
    {
      body: { ...validPayload, phpVersion: '' },
      error: 'missing_metadata',
      name: 'missing metadata'
    }
  ];

  for (const currentCase of cases) {
    await t.test(currentCase.name, async () => {
      const response = await handleRequest(checkInRequest(currentCase.body), makeEnv(), undefined, now);

      assert.equal(response.status, 400);
      assert.deepEqual(await response.json(), { error: currentCase.error });
    });
  }
});

test('oversized check-in body is rejected before storage', async () => {
  const env = makeEnv();
  const response = await handleRequest(
    new Request('https://telemetry.example/v1/check-in', {
      body: JSON.stringify({ ...validPayload, extra: 'x'.repeat(2100) }),
      method: 'POST'
    }),
    env,
    undefined,
    now
  );

  assert.equal(response.status, 413);
  assert.equal(env.DB.rows.size, 0);
});

test('summary requires bearer admin token', async () => {
  const env = makeEnv();
  const response = await handleRequest(new Request('https://telemetry.example/v1/summary?window=30d'), env, undefined, now);

  assert.equal(response.status, 401);
  assert.deepEqual(await response.json(), { error: 'unauthorized' });
});

test('summary returns active installs inside the requested window', async () => {
  const env = makeEnv();

  env.DB.rows.set('a'.repeat(64), row({ site_hash: 'a'.repeat(64), last_seen: '2026-06-29T00:00:00.000Z' }));
  env.DB.rows.set('b'.repeat(64), row({ site_hash: 'b'.repeat(64), last_seen: '2026-05-01T00:00:00.000Z' }));

  const response = await handleRequest(
    new Request('https://telemetry.example/v1/summary?window=30d', {
      headers: { Authorization: 'Bearer test-token' }
    }),
    env,
    undefined,
    now
  );
  const body = await response.json();

  assert.equal(response.status, 200);
  assert.equal(body.activeInstalls, 1);
  assert.equal(body.window, '30d');
  assert.deepEqual(body.byPluginVersion, [{ installs: 1, pluginVersion: '1.1.1' }]);
});

test('summary rejects windows outside retention', async () => {
  const response = await handleRequest(
    new Request('https://telemetry.example/v1/summary?window=365d', {
      headers: { Authorization: 'Bearer test-token' }
    }),
    makeEnv(),
    undefined,
    now
  );

  assert.equal(response.status, 400);
  assert.deepEqual(await response.json(), { error: 'invalid_window' });
});

test('cleanup removes rows older than 90 days', async () => {
  const env = makeEnv();

  env.DB.rows.set('a'.repeat(64), row({ site_hash: 'a'.repeat(64), last_seen: '2026-03-01T00:00:00.000Z' }));
  env.DB.rows.set('b'.repeat(64), row({ site_hash: 'b'.repeat(64), last_seen: '2026-06-01T00:00:00.000Z' }));

  await cleanupOldSites(env, now);

  assert.deepEqual([...env.DB.rows.keys()], ['b'.repeat(64)]);
});

function checkInRequest(body) {
  return new Request('https://telemetry.example/v1/check-in', {
    body: JSON.stringify(body),
    headers: { 'Content-Type': 'application/json' },
    method: 'POST'
  });
}

function makeEnv() {
  return {
    ADMIN_TOKEN: 'test-token',
    DB: new FakeD1()
  };
}

function row(overrides) {
  return {
    checkin_count: 1,
    consent_version: '2026-06-30',
    first_seen: overrides.last_seen,
    last_seen: overrides.last_seen,
    php_version: '8.3.1',
    plugin_slug: 'brasth-document-sync-for-google-docs',
    plugin_version: '1.1.1',
    site_hash: overrides.site_hash,
    wp_version: '6.6.2',
    ...overrides
  };
}

class FakeD1 {
  constructor() {
    this.rows = new Map();
  }

  prepare(sql) {
    return new FakeStatement(this, sql);
  }
}

class FakeStatement {
  constructor(db, sql) {
    this.db = db;
    this.params = [];
    this.sql = sql;
  }

  bind(...params) {
    this.params = params;

    return this;
  }

  async run() {
    if (this.sql.includes('INSERT INTO sites')) {
      const [siteHash, pluginSlug, pluginVersion, wpVersion, phpVersion, consentVersion, seenAt] = this.params;
      const existing = this.db.rows.get(siteHash);

      this.db.rows.set(siteHash, {
        checkin_count: existing ? existing.checkin_count + 1 : 1,
        consent_version: consentVersion,
        first_seen: existing ? existing.first_seen : seenAt,
        last_seen: seenAt,
        php_version: phpVersion,
        plugin_slug: pluginSlug,
        plugin_version: pluginVersion,
        site_hash: siteHash,
        wp_version: wpVersion
      });

      return { success: true };
    }

    if (this.sql.includes('DELETE FROM sites')) {
      const [cutoff] = this.params;
      let changes = 0;

      for (const [siteHash, currentRow] of this.db.rows.entries()) {
        if (currentRow.last_seen < cutoff) {
          this.db.rows.delete(siteHash);
          changes += 1;
        }
      }

      return { meta: { changes }, success: true };
    }

    throw new Error(`Unexpected run SQL: ${this.sql}`);
  }

  async first() {
    if (this.sql.includes('COUNT(*) AS activeInstalls')) {
      const [activeSince] = this.params;

      return {
        activeInstalls: this.activeRows(activeSince).length
      };
    }

    throw new Error(`Unexpected first SQL: ${this.sql}`);
  }

  async all() {
    const [activeSince] = this.params;
    const rows = this.activeRows(activeSince);

    if (this.sql.includes('plugin_version AS pluginVersion')) {
      return { results: grouped(rows, 'plugin_version', 'pluginVersion') };
    }

    if (this.sql.includes('wp_version AS wpVersion')) {
      return { results: grouped(rows, 'wp_version', 'wpVersion') };
    }

    if (this.sql.includes('php_version AS phpVersion')) {
      return { results: grouped(rows, 'php_version', 'phpVersion') };
    }

    throw new Error(`Unexpected all SQL: ${this.sql}`);
  }

  activeRows(activeSince) {
    return [...this.db.rows.values()].filter((currentRow) => currentRow.last_seen >= activeSince);
  }
}

function grouped(rows, key, alias) {
  const counts = new Map();

  for (const currentRow of rows) {
    counts.set(currentRow[key], (counts.get(currentRow[key]) ?? 0) + 1);
  }

  return [...counts.entries()]
    .map(([value, installs]) => ({ [alias]: value, installs }))
    .sort((left, right) => right.installs - left.installs || String(left[alias]).localeCompare(String(right[alias])));
}
