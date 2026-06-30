const PLUGIN_SLUG = 'brasth-document-sync-for-google-docs';
const MAX_BODY_BYTES = 2048;
const MAX_FIELD_LENGTH = 128;
const RETENTION_DAYS = 90;
const DEFAULT_SUMMARY_WINDOW_DAYS = 30;

export default {
  async fetch(request, env, ctx) {
    return handleRequest(request, env, ctx);
  },

  async scheduled(_event, env, ctx) {
    ctx.waitUntil(cleanupOldSites(env));
  }
};

export async function handleRequest(request, env, _ctx, now = new Date()) {
  const url = new URL(request.url);

  if (request.method === 'GET' && url.pathname === '/health') {
    return jsonResponse({ ok: true });
  }

  if (request.method === 'POST' && url.pathname === '/v1/check-in') {
    return handleCheckIn(request, env, now);
  }

  if (request.method === 'GET' && url.pathname === '/v1/summary') {
    return handleSummary(request, env, now);
  }

  return jsonResponse({ error: 'not_found' }, 404);
}

export async function cleanupOldSites(env, now = new Date()) {
  const cutoff = toIsoString(daysAgo(now, RETENTION_DAYS));

  return env.DB
    .prepare('DELETE FROM sites WHERE last_seen < ?1')
    .bind(cutoff)
    .run();
}

async function handleCheckIn(request, env, now) {
  const contentLength = Number(request.headers.get('content-length') ?? 0);

  if (contentLength > MAX_BODY_BYTES) {
    return jsonResponse({ error: 'body_too_large' }, 413);
  }

  const body = await request.text();

  if (new TextEncoder().encode(body).byteLength > MAX_BODY_BYTES) {
    return jsonResponse({ error: 'body_too_large' }, 413);
  }

  const parsed = parseJsonObject(body);

  if (!parsed.ok) {
    return jsonResponse({ error: 'invalid_json' }, 400);
  }

  const payload = normalizeCheckIn(parsed.value);

  if (!payload.ok) {
    return jsonResponse({ error: payload.error }, 400);
  }

  const seenAt = toIsoString(now);

  await env.DB
    .prepare(
      `INSERT INTO sites (
        site_hash,
        plugin_slug,
        plugin_version,
        wp_version,
        php_version,
        consent_version,
        first_seen,
        last_seen,
        checkin_count
      ) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?7, 1)
      ON CONFLICT(site_hash) DO UPDATE SET
        plugin_slug = excluded.plugin_slug,
        plugin_version = excluded.plugin_version,
        wp_version = excluded.wp_version,
        php_version = excluded.php_version,
        consent_version = excluded.consent_version,
        last_seen = excluded.last_seen,
        checkin_count = sites.checkin_count + 1`
    )
    .bind(
      payload.value.siteHash,
      payload.value.pluginSlug,
      payload.value.pluginVersion,
      payload.value.wpVersion,
      payload.value.phpVersion,
      payload.value.consentVersion,
      seenAt
    )
    .run();

  return jsonResponse({ ok: true });
}

async function handleSummary(request, env, now) {
  const adminToken = typeof env.ADMIN_TOKEN === 'string' ? env.ADMIN_TOKEN : '';
  const authorization = request.headers.get('authorization') ?? '';

  if (adminToken === '' || authorization !== `Bearer ${adminToken}`) {
    return jsonResponse({ error: 'unauthorized' }, 401);
  }

  const url = new URL(request.url);
  const windowDays = parseWindowDays(url.searchParams.get('window') ?? `${DEFAULT_SUMMARY_WINDOW_DAYS}d`);

  if (windowDays === null) {
    return jsonResponse({ error: 'invalid_window' }, 400);
  }

  const generatedAt = toIsoString(now);
  const activeSince = toIsoString(daysAgo(now, windowDays));
  const activeRow = await env.DB
    .prepare('SELECT COUNT(*) AS activeInstalls FROM sites WHERE last_seen >= ?1')
    .bind(activeSince)
    .first();

  const byPluginVersion = await groupedSummary(env, activeSince, 'plugin_version', 'pluginVersion');
  const byWpVersion = await groupedSummary(env, activeSince, 'wp_version', 'wpVersion');
  const byPhpVersion = await groupedSummary(env, activeSince, 'php_version', 'phpVersion');

  return jsonResponse({
    activeInstalls: Number(activeRow?.activeInstalls ?? 0),
    activeSince,
    byPhpVersion,
    byPluginVersion,
    byWpVersion,
    generatedAt,
    window: `${windowDays}d`
  });
}

async function groupedSummary(env, activeSince, column, alias) {
  const rows = await env.DB
    .prepare(
      `SELECT ${column} AS ${alias}, COUNT(*) AS installs
      FROM sites
      WHERE last_seen >= ?1
      GROUP BY ${column}
      ORDER BY installs DESC, ${column} ASC`
    )
    .bind(activeSince)
    .all();

  return (rows.results ?? []).map((row) => ({
    [alias]: String(row[alias] ?? ''),
    installs: Number(row.installs ?? 0)
  }));
}

function parseJsonObject(body) {
  try {
    const value = JSON.parse(body);

    if (value === null || Array.isArray(value) || typeof value !== 'object') {
      return { ok: false };
    }

    return { ok: true, value };
  } catch (_error) {
    return { ok: false };
  }
}

function normalizeCheckIn(value) {
  const siteHash = normalizeString(value.siteHash);
  const pluginSlug = normalizeString(value.pluginSlug);
  const pluginVersion = normalizeString(value.pluginVersion);
  const wpVersion = normalizeString(value.wpVersion);
  const phpVersion = normalizeString(value.phpVersion);
  const consentVersion = normalizeString(value.consentVersion);

  if (!/^[a-f0-9]{64}$/i.test(siteHash)) {
    return { ok: false, error: 'invalid_site_hash' };
  }

  if (pluginSlug !== PLUGIN_SLUG) {
    return { ok: false, error: 'invalid_plugin_slug' };
  }

  if (pluginVersion === '' || wpVersion === '' || phpVersion === '' || consentVersion === '') {
    return { ok: false, error: 'missing_metadata' };
  }

  return {
    ok: true,
    value: {
      consentVersion,
      phpVersion,
      pluginSlug,
      pluginVersion,
      siteHash: siteHash.toLowerCase(),
      wpVersion
    }
  };
}

function normalizeString(value) {
  return typeof value === 'string' ? value.trim().slice(0, MAX_FIELD_LENGTH) : '';
}

function parseWindowDays(value) {
  const match = /^([1-9][0-9]*)d$/.exec(value);

  if (!match) {
    return null;
  }

  const days = Number(match[1]);

  return days >= 1 && days <= RETENTION_DAYS ? days : null;
}

function daysAgo(now, days) {
  return new Date(now.getTime() - days * 24 * 60 * 60 * 1000);
}

function toIsoString(date) {
  return date.toISOString();
}

function jsonResponse(body, status = 200) {
  return new Response(JSON.stringify(body), {
    headers: {
      'Content-Type': 'application/json; charset=utf-8'
    },
    status
  });
}
