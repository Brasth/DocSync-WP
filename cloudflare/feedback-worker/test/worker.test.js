import assert from 'node:assert/strict';
import test from 'node:test';

import { handleRequest } from '../src/index.js';

const validPayload = {
  context: {
    phpVersion: '8.3.1',
    pluginVersion: '1.1.4',
    wpVersion: '6.6.2'
  },
  description: 'Source picker needs a retry after saving a document so the sync can complete.',
  title: 'Source picker needs a retry',
  type: 'bug'
};

const githubIssue = {
  html_url: 'https://github.com/Brasth/DocSync-WP/issues/42',
  number: 42
};

test('health returns ok', async () => {
  const response = await handleRequest(new Request('https://feedback.example/health'), makeEnv());

  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), { ok: true });
});

test('valid feedback creates a GitHub issue and returns only safe issue data', async () => {
  let capturedRequest;
  const env = makeEnv({
    GITHUB_FETCH: async (url, options) => {
      capturedRequest = { options, url };
      return new Response(JSON.stringify(githubIssue), { status: 201 });
    }
  });

  const response = await handleRequest(feedbackRequest(validPayload), env);
  const body = await response.json();
  const githubBody = JSON.parse(capturedRequest.options.body);

  assert.equal(response.status, 200);
  assert.deepEqual(body, { issue: { number: 42, url: githubIssue.html_url }, ok: true });
  assert.equal(capturedRequest.url, 'https://api.github.com/repos/Brasth/DocSync-WP/issues');
  assert.equal(capturedRequest.options.headers.Authorization, 'Bearer test-github-token');
  assert.match(githubBody.title, /^\[Bug\]/);
  assert.match(githubBody.body, /Source picker needs a retry/);
});

test('invalid feedback is rejected before calling GitHub', async () => {
  let called = false;
  const env = makeEnv({
    GITHUB_FETCH: async () => {
      called = true;
      return new Response('{}', { status: 201 });
    }
  });

  const response = await handleRequest(
    feedbackRequest({ ...validPayload, description: '' }),
    env
  );

  assert.equal(response.status, 400);
  assert.deepEqual(await response.json(), { error: 'invalid_description' });
  assert.equal(called, false);
});

test('empty titles are rejected before calling GitHub', async () => {
  const response = await handleRequest(
    feedbackRequest({ ...validPayload, title: '' }),
    makeEnv()
  );

  assert.equal(response.status, 400);
  assert.deepEqual(await response.json(), { error: 'invalid_title' });
});

test('configured shared secret is required', async () => {
  const env = makeEnv({ WORKER_SHARED_SECRET: 'worker-secret' });
  const response = await handleRequest(feedbackRequest(validPayload), env);

  assert.equal(response.status, 401);
  assert.deepEqual(await response.json(), { error: 'unauthorized' });
});

test('missing GitHub token returns a configuration error', async () => {
  const env = makeEnv({ GITHUB_TOKEN: '' });
  const response = await handleRequest(feedbackRequest(validPayload), env);

  assert.equal(response.status, 503);
  assert.deepEqual(await response.json(), { error: 'service_not_configured' });
});

test('GitHub failures are hidden behind a safe error', async () => {
  const env = makeEnv({
    GITHUB_FETCH: async () => new Response(JSON.stringify({ message: 'token must not leak' }), { status: 403 })
  });
  const response = await handleRequest(feedbackRequest(validPayload), env);

  assert.equal(response.status, 502);
  assert.deepEqual(await response.json(), { error: 'github_rejected' });
});

function feedbackRequest(body, headers = {}) {
  return new Request('https://feedback.example/v1/issues', {
    body: JSON.stringify(body),
    headers: { 'Content-Type': 'application/json', ...headers },
    method: 'POST'
  });
}

function makeEnv(overrides = {}) {
  return {
    GITHUB_OWNER: 'Brasth',
    GITHUB_REPOSITORY: 'DocSync-WP',
    GITHUB_TOKEN: 'test-github-token',
    ...overrides
  };
}
