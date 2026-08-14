const DEFAULT_OWNER = 'Brasth';
const DEFAULT_REPOSITORY = 'DocSync-WP';
const MAX_BODY_BYTES = 12288;
const MAX_DESCRIPTION_LENGTH = 10000;
const MAX_TITLE_LENGTH = 120;
const MIN_DESCRIPTION_LENGTH = 1;
const MIN_TITLE_LENGTH = 1;
const TYPES = new Set(['bug', 'feature', 'question']);
const TYPE_LABELS = {
  bug: 'Bug',
  feature: 'Feature request',
  question: 'Question'
};

export default {
  async fetch(request, env) {
    return handleRequest(request, env);
  }
};

export async function handleRequest(request, env) {
  const url = new URL(request.url);

  if (request.method === 'GET' && url.pathname === '/health') {
    return jsonResponse({ ok: true });
  }

  if (request.method === 'POST' && url.pathname === '/v1/issues') {
    return handleCreateIssue(request, env);
  }

  return jsonResponse({ error: 'not_found' }, 404);
}

async function handleCreateIssue(request, env) {
  const configuredSecret = typeof env.WORKER_SHARED_SECRET === 'string' ? env.WORKER_SHARED_SECRET : '';

  if (configuredSecret !== '' && request.headers.get('X-DocSync-Feedback-Secret') !== configuredSecret) {
    return jsonResponse({ error: 'unauthorized' }, 401);
  }

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

  const payload = normalizeIssue(parsed.value);

  if (!payload.ok) {
    return jsonResponse({ error: payload.error }, 400);
  }

  const token = typeof env.GITHUB_TOKEN === 'string' ? env.GITHUB_TOKEN : '';

  if (token === '') {
    return jsonResponse({ error: 'service_not_configured' }, 503);
  }

  const owner = typeof env.GITHUB_OWNER === 'string' && env.GITHUB_OWNER.trim() !== ''
    ? env.GITHUB_OWNER.trim()
    : DEFAULT_OWNER;
  const repository = typeof env.GITHUB_REPOSITORY === 'string' && env.GITHUB_REPOSITORY.trim() !== ''
    ? env.GITHUB_REPOSITORY.trim()
    : DEFAULT_REPOSITORY;
  const githubUrl = `https://api.github.com/repos/${encodeURIComponent(owner)}/${encodeURIComponent(repository)}/issues`;
  const githubFetch = typeof env.GITHUB_FETCH === 'function' ? env.GITHUB_FETCH : fetch;

  let githubResponse;

  try {
    githubResponse = await githubFetch(githubUrl, {
      body: JSON.stringify({
        body: buildIssueBody(payload.value),
        title: `[${TYPE_LABELS[payload.value.type]}] ${payload.value.title}`
      }),
      headers: {
        Accept: 'application/vnd.github+json',
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        'User-Agent': 'DocSync-WP-feedback-worker',
        'X-GitHub-Api-Version': '2022-11-28'
      },
      method: 'POST'
    });
  } catch (_error) {
    return jsonResponse({ error: 'github_unavailable' }, 502);
  }

  if (!githubResponse.ok) {
    return jsonResponse({ error: 'github_rejected' }, 502);
  }

  const githubIssue = await readJsonObject(githubResponse);
  const issueUrl = githubIssue?.html_url;
  const issueNumber = githubIssue?.number;

  if (!isGithubIssueUrl(issueUrl) || !Number.isInteger(issueNumber) || issueNumber <= 0) {
    return jsonResponse({ error: 'invalid_github_response' }, 502);
  }

  return jsonResponse({
    issue: {
      number: issueNumber,
      url: issueUrl
    },
    ok: true
  });
}

function buildIssueBody(payload) {
  const context = payload.context;
  const contextLines = [
    `Plugin version: ${context.pluginVersion}`,
    `WordPress version: ${context.wpVersion}`,
    `PHP version: ${context.phpVersion}`
  ];

  return `${payload.description}\n\n---\nSubmitted through the Brasth Document Sync admin feedback form.\n\n${contextLines.join('\n')}`;
}

function normalizeIssue(value) {
  const type = normalizeString(value.type, 32);
  const title = normalizeTitle(value.title);
  const description = normalizeString(value.description, MAX_DESCRIPTION_LENGTH);
  const context = normalizeContext(value.context);

  if (!TYPES.has(type)) {
    return { error: 'invalid_type', ok: false };
  }

  if (title.length < MIN_TITLE_LENGTH || title.length > MAX_TITLE_LENGTH) {
    return { error: 'invalid_title', ok: false };
  }

  if (description.length < MIN_DESCRIPTION_LENGTH || description.length > MAX_DESCRIPTION_LENGTH) {
    return { error: 'invalid_description', ok: false };
  }

  return {
    ok: true,
    value: {
      context,
      description,
      title,
      type
    }
  };
}

function normalizeContext(value) {
  const context = value !== null && typeof value === 'object' && !Array.isArray(value) ? value : {};

  return {
    phpVersion: normalizeString(context.phpVersion, 64) || 'unknown',
    pluginVersion: normalizeString(context.pluginVersion, 64) || 'unknown',
    wpVersion: normalizeString(context.wpVersion, 64) || 'unknown'
  };
}

function normalizeTitle(value) {
  return normalizeString(value, MAX_TITLE_LENGTH).replace(/\s+/g, ' ');
}

function normalizeString(value, maxLength) {
  if (typeof value !== 'string') {
    return '';
  }

  return value.replace(/[^\P{C}\t\n\r]/gu, '').trim().slice(0, maxLength);
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

async function readJsonObject(response) {
  try {
    const value = await response.json();

    return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : null;
  } catch (_error) {
    return null;
  }
}

function isGithubIssueUrl(value) {
  if (typeof value !== 'string') {
    return false;
  }

  try {
    const url = new URL(value);

    return url.protocol === 'https:' && url.hostname === 'github.com' && /^\/[^/]+\/[^/]+\/issues\/[1-9][0-9]*$/.test(url.pathname);
  } catch (_error) {
    return false;
  }
}

function jsonResponse(body, status = 200) {
  return new Response(JSON.stringify(body), {
    headers: {
      'Cache-Control': 'no-store',
      'Content-Type': 'application/json; charset=utf-8'
    },
    status
  });
}
