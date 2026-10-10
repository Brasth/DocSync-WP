import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { getAdminConfig } from '../config';

type ApiFetchOptions = {
  method?: string;
  data?: unknown;
  headers?: Record<string, string>;
};

export type AdminApiErrorData = Record<string, unknown> & { status?: number };

export class AdminApiError extends Error {
  code: string;
  status: number;
  data: AdminApiErrorData;

  constructor(message: string, code = 'request_failed', data: AdminApiErrorData = {}) {
    super(message);
    this.code = code;
    this.data = data;
    this.status = typeof data.status === 'number' ? data.status : 0;
  }
}

const INVALID_NONCE_CODE = 'rest_cookie_invalid_nonce';

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
};

const endpointUrl = (endpoint: string): string => {
  const config = getAdminConfig();
  const base = config.restUrl.replace(/\/$/, '');
  return `${base}/${endpoint.replace(/^\//, '')}`;
};

/**
 * Absolute REST URL for an endpoint, optionally with the REST nonce as `_wpnonce`
 * so browser-initiated loads (images, PDF.js) authenticate with the cookie session.
 */
export const restEndpointUrl = (endpoint: string, withNonce = false): string => {
  const url = endpointUrl(endpoint);

  if (!withNonce) {
    return url;
  }

  const separator = url.includes('?') ? '&' : '?';

  return `${url}${separator}_wpnonce=${encodeURIComponent(getAdminConfig().nonce)}`;
};

const toAdminApiError = (caught: unknown): AdminApiError => {
  if (caught instanceof AdminApiError) {
    return caught;
  }

  if (isRecord(caught) && typeof caught.message === 'string') {
    const code = typeof caught.code === 'string' ? caught.code : '';
    const data = isRecord(caught.data) ? caught.data as AdminApiErrorData : {};
    const message = code === 'fetch_error'
      ? __('Could not reach the server. Check your connection, then retry.', 'brasth-document-sync-for-google-docs')
      : code === 'docsync_wp_docs_api_unavailable' && !caught.message.includes('Google Docs API')
        ? __('Enable Google Docs API in the same Google Cloud project, then retry sync.', 'brasth-document-sync-for-google-docs')
        : caught.message;

    return new AdminApiError(message, code, data);
  }

  return new AdminApiError(__('Could not complete the request. Please retry.', 'brasth-document-sync-for-google-docs'));
};

let nonceRefresh: Promise<boolean> | null = null;

/**
 * Fetch a fresh REST nonce from WordPress core (`admin-ajax.php?action=rest-nonce`)
 * after the page's nonce expired. Resolves false when no fresh nonce is available.
 */
export const refreshRestNonce = (): Promise<boolean> => {
  if (nonceRefresh) {
    return nonceRefresh;
  }

  const ajaxUrl = (window as Window & { ajaxurl?: string }).ajaxurl;

  if (!ajaxUrl) {
    return Promise.resolve(false);
  }

  nonceRefresh = fetch(`${ajaxUrl}${ajaxUrl.includes('?') ? '&' : '?'}action=rest-nonce`, { credentials: 'same-origin' })
    .then(async (response) => {
      const nonce = response.ok ? (await response.text()).trim() : '';

      if (!/^[a-f0-9]{6,32}$/i.test(nonce)) {
        return false;
      }

      window.DocSyncWPAdmin = { ...(window.DocSyncWPAdmin ?? {}), nonce };
      return true;
    })
    .catch(() => false)
    .finally(() => {
      nonceRefresh = null;
    });

  return nonceRefresh;
};

const sendRequest = <T>(endpoint: string, options: ApiFetchOptions): Promise<T> => {
  return apiFetch<T>({
    ...options,
    url: endpointUrl(endpoint),
    headers: {
      ...(options.headers ?? {}),
      'X-WP-Nonce': getAdminConfig().nonce
    }
  });
};

export const request = async <T>(endpoint: string, options: ApiFetchOptions = {}): Promise<T> => {
  try {
    return await sendRequest<T>(endpoint, options);
  } catch (caught) {
    const error = toAdminApiError(caught);

    if (error.code === INVALID_NONCE_CODE && await refreshRestNonce()) {
      try {
        return await sendRequest<T>(endpoint, options);
      } catch (retryCaught) {
        throw toAdminApiError(retryCaught);
      }
    }

    throw error;
  }
};

export type UploadProgress = { loaded: number; total: number };

type XhrOptions = {
  method: 'POST' | 'PUT';
  body: FormData | Blob;
  contentType?: string;
  signal?: AbortSignal;
  onProgress?: (progress: UploadProgress) => void;
};

export const REQUEST_ABORTED_CODE = 'docsync_wp_request_aborted';

const sendXhr = <T>(endpoint: string, options: XhrOptions): Promise<T> => {
  return new Promise<T>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const abort = () => xhr.abort();

    if (options.signal?.aborted) {
      reject(new AdminApiError(__('Upload cancelled.', 'brasth-document-sync-for-google-docs'), REQUEST_ABORTED_CODE));
      return;
    }

    xhr.open(options.method, endpointUrl(endpoint));
    xhr.withCredentials = true;
    xhr.setRequestHeader('X-WP-Nonce', getAdminConfig().nonce);
    xhr.setRequestHeader('Accept', 'application/json');

    if (options.contentType) {
      xhr.setRequestHeader('Content-Type', options.contentType);
    }

    if (options.onProgress) {
      const report = options.onProgress;

      xhr.upload.addEventListener('progress', (event) => {
        report({ loaded: event.loaded, total: event.lengthComputable ? event.total : 0 });
      });
    }

    options.signal?.addEventListener('abort', abort, { once: true });

    xhr.addEventListener('load', () => {
      options.signal?.removeEventListener('abort', abort);
      let payload: unknown = null;

      try {
        payload = xhr.responseText ? JSON.parse(xhr.responseText) : null;
      } catch {
        payload = null;
      }

      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(payload as T);
        return;
      }

      if (isRecord(payload) && typeof payload.message === 'string') {
        reject(toAdminApiError(payload));
        return;
      }

      reject(new AdminApiError(
        xhr.status === 413
          ? __('The server refused this upload because it is larger than the host allows.', 'brasth-document-sync-for-google-docs')
          : __('Could not complete the upload. Please retry.', 'brasth-document-sync-for-google-docs'),
        xhr.status === 413 ? 'docsync_wp_import_file_too_large' : 'request_failed',
        { status: xhr.status }
      ));
    });
    xhr.addEventListener('error', () => {
      options.signal?.removeEventListener('abort', abort);
      reject(new AdminApiError(__('Could not reach the server. Check your connection, then retry.', 'brasth-document-sync-for-google-docs'), 'fetch_error'));
    });
    xhr.addEventListener('abort', () => {
      options.signal?.removeEventListener('abort', abort);
      reject(new AdminApiError(__('Upload cancelled.', 'brasth-document-sync-for-google-docs'), REQUEST_ABORTED_CODE));
    });

    xhr.send(options.body);
  });
};

/**
 * Send a multipart or binary body with XMLHttpRequest so the caller sees byte progress
 * and can cancel. An expired REST nonce is refreshed once and the request is resent.
 */
export const requestWithProgress = async <T>(endpoint: string, options: XhrOptions): Promise<T> => {
  try {
    return await sendXhr<T>(endpoint, options);
  } catch (caught) {
    const error = toAdminApiError(caught);

    if (error.code === INVALID_NONCE_CODE && await refreshRestNonce()) {
      return sendXhr<T>(endpoint, options);
    }

    throw error;
  }
};
