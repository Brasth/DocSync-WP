import type { DocSyncWPAdminConfig } from './config';

type PickerDocument = {
  id: string;
  name: string;
  url: string;
};

type TokenResponse = {
  access_token?: string;
  error?: string;
  error_description?: string;
};

type TokenClient = {
  requestAccessToken: (options?: { prompt?: string }) => void;
};

type GoogleAccounts = {
  oauth2: {
    initTokenClient: (config: {
      client_id: string;
      scope: string;
      prompt?: string;
      callback: (response: TokenResponse) => void;
    }) => TokenClient;
  };
};

type PickerBuilder = {
  addView: (view: unknown) => PickerBuilder;
  setOAuthToken: (token: string) => PickerBuilder;
  setDeveloperKey: (key: string) => PickerBuilder;
  setAppId: (appId: string) => PickerBuilder;
  setOrigin: (origin: string) => PickerBuilder;
  setCallback: (callback: (data: Record<string, unknown>) => void) => PickerBuilder;
  build: () => { setVisible: (visible: boolean) => void };
};

type PickerApi = {
  Action: { PICKED: string; CANCEL: string };
  Response: { ACTION: string; DOCUMENTS: string };
  Document: { ID: string; NAME: string; URL: string };
  DocsView: new () => { setMimeTypes: (mimeTypes: string) => unknown };
  PickerBuilder: new () => PickerBuilder;
};

type Gapi = {
  load: (api: string, options: { callback: () => void; onerror: () => void }) => void;
};

declare global {
  interface Window {
    google?: {
      accounts?: GoogleAccounts;
      picker?: PickerApi;
    };
    gapi?: Gapi;
  }
}

const DRIVE_FILE_SCOPE = 'https://www.googleapis.com/auth/drive.file';
const GOOGLE_DOC_MIME = 'application/vnd.google-apps.document';

const buildTokenErrorMessage = (response: TokenResponse): string => {
  const details = [response.error, response.error_description].filter(Boolean).join(': ');
  const originMismatch = response.error === 'invalid_client' || response.error_description?.toLowerCase().includes('origin');
  return [
    `Google did not grant Picker access${details ? ` (${details})` : ''}.`,
    originMismatch ? `Add ${window.location.origin} to the OAuth client Authorized JavaScript origins.` : ''
  ].filter(Boolean).join(' ');
};

const loadScript = (id: string, src: string): Promise<void> => {
  const existing = document.getElementById(id);

  if (existing) {
    return Promise.resolve();
  }

  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.id = id;
    script.src = src;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error(`Could not load ${src}`));
    document.head.appendChild(script);
  });
};

const loadPickerApi = async (): Promise<PickerApi> => {
  await loadScript('docsync-wp-google-api', 'https://apis.google.com/js/api.js');

  if (!window.gapi) {
    throw new Error('Google API script loaded without gapi.');
  }

  await new Promise<void>((resolve, reject) => {
    window.gapi?.load('picker', {
      callback: () => resolve(),
      onerror: () => reject(new Error('Google Picker API could not load.'))
    });
  });

  if (!window.google?.picker) {
    throw new Error('Google Picker API is unavailable.');
  }

  return window.google.picker;
};

const requestPickerToken = async (clientId: string): Promise<string> => {
  await loadScript('docsync-wp-google-identity', 'https://accounts.google.com/gsi/client');

  const accounts = window.google?.accounts;

  if (!accounts) {
    throw new Error('Google Identity Services is unavailable.');
  }

  return new Promise((resolve, reject) => {
    const tokenClient = accounts.oauth2.initTokenClient({
      client_id: clientId,
      scope: DRIVE_FILE_SCOPE,
      prompt: '',
      callback: (response) => {
        if (response.error || !response.access_token) {
          reject(new Error(buildTokenErrorMessage(response)));
          return;
        }

        resolve(response.access_token);
      }
    });

    tokenClient.requestAccessToken({ prompt: 'consent' });
  });
};

export const chooseGoogleDoc = async (config: DocSyncWPAdminConfig): Promise<PickerDocument> => {
  if (!config.clientId || !config.pickerApiKey || !config.pickerAppId) {
    throw new Error('Configure Google client ID, Picker API key, and Picker app ID first.');
  }

  const [picker, accessToken] = await Promise.all([
    loadPickerApi(),
    requestPickerToken(config.clientId)
  ]);

  return new Promise((resolve, reject) => {
    const view = new picker.DocsView().setMimeTypes(GOOGLE_DOC_MIME);

    const pickerInstance = new picker.PickerBuilder()
      .addView(view)
      .setOAuthToken(accessToken)
      .setDeveloperKey(config.pickerApiKey)
      .setAppId(config.pickerAppId)
      .setOrigin(window.location.origin)
      .setCallback((data) => {
        const action = data[picker.Response.ACTION];

        if (action === picker.Action.CANCEL) {
          reject(new Error('Google Picker was closed.'));
          return;
        }

        if (action !== picker.Action.PICKED) {
          return;
        }

        const documents = data[picker.Response.DOCUMENTS];

        if (!Array.isArray(documents) || documents.length === 0) {
          reject(new Error('Google Picker did not return a document.'));
          return;
        }

        const document = documents[0] as Record<string, unknown>;
        const id = document[picker.Document.ID];
        const name = document[picker.Document.NAME];
        const url = document[picker.Document.URL];

        if (typeof id !== 'string' || typeof name !== 'string') {
          reject(new Error('Google Picker returned an invalid document.'));
          return;
        }

        resolve({
          id,
          name,
          url: typeof url === 'string' ? url : ''
        });
      })
      .build();

    pickerInstance.setVisible(true);
  });
};
