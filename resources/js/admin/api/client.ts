import apiFetch from '@wordpress/api-fetch';

import { getAdminConfig } from '../config';

type ApiFetchOptions = {
  method?: string;
  data?: unknown;
  headers?: Record<string, string>;
};

const endpointUrl = (endpoint: string): string => {
  const config = getAdminConfig();
  const base = config.restUrl.replace(/\/$/, '');
  return `${base}/${endpoint.replace(/^\//, '')}`;
};

export const request = async <T>(endpoint: string, options: ApiFetchOptions = {}): Promise<T> => {
  const config = getAdminConfig();

  try {
    return await apiFetch<T>({
      ...options,
      url: endpointUrl(endpoint),
      headers: {
        ...(options.headers ?? {}),
        'X-WP-Nonce': config.nonce
      }
    });
  } catch (caught) {
    if (caught && typeof caught === 'object' && 'message' in caught && typeof caught.message === 'string') {
      throw new Error(caught.message);
    }

    throw caught;
  }
};
