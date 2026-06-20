import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

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
      const code = 'code' in caught && typeof caught.code === 'string' ? caught.code : '';
      const message = code === 'docsync_wp_docs_api_unavailable' && !caught.message.includes('Google Docs API')
        ? __('Enable Google Docs API in the same Google Cloud project, then retry sync.', 'brasth-document-sync-for-google-docs')
        : caught.message;

      throw new Error(message);
    }

    throw caught;
  }
};
