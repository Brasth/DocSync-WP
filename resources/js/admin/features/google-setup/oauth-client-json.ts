export type OAuthClientJsonCredentials = {
  clientId: string;
  clientSecret: string;
  redirectUris: string[];
};

type OAuthClientJsonShape = {
  web?: {
    client_id?: unknown;
    client_secret?: unknown;
    redirect_uris?: unknown;
  };
};

export const parseOAuthClientJson = (json: string): OAuthClientJsonCredentials => {
  let parsed: OAuthClientJsonShape;

  try {
    parsed = JSON.parse(json) as OAuthClientJsonShape;
  } catch {
    throw new Error('Could not parse this OAuth JSON file.');
  }

  const web = parsed.web;

  if (!web || typeof web !== 'object') {
    throw new Error('Use the OAuth client JSON for a Google Web application.');
  }

  if (typeof web.client_id !== 'string' || web.client_id.trim() === '') {
    throw new Error('OAuth JSON is missing web.client_id.');
  }

  if (typeof web.client_secret !== 'string' || web.client_secret.trim() === '') {
    throw new Error('OAuth JSON is missing web.client_secret.');
  }

  return {
    clientId: web.client_id.trim(),
    clientSecret: web.client_secret.trim(),
    redirectUris: Array.isArray(web.redirect_uris)
      ? web.redirect_uris.filter((uri): uri is string => typeof uri === 'string')
      : []
  };
};
