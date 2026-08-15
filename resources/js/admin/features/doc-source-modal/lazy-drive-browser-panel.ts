import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { DocumentMetadata } from '../../api';
import { getAdminConfig } from '../../config';

export type DriveBrowserPanelProps = {
  allowMultiSelect?: boolean;
  busy: boolean;
  selectedDocument: DocumentMetadata | null;
  selectedDocuments?: DocumentMetadata[];
  onSelect: (document: DocumentMetadata | null) => void;
};

type DriveBrowserComponent = (props: DriveBrowserPanelProps) => JSX.Element;
type DriveBrowserBundle = {
  DriveBrowserPanel: DriveBrowserComponent;
};

declare global {
  interface Window {
    DocSyncWPDriveBrowserBundle?: DriveBrowserBundle;
  }
}

let loadPromise: Promise<DriveBrowserBundle> | null = null;

export const ensureLazyStyle = (href: string, id: string) => {
  if (!href) {
    return;
  }

  if (document.getElementById(id)) {
    return;
  }

  const link = document.createElement('link');

  link.id = id;
  link.href = href;
  link.rel = 'stylesheet';
  document.head.appendChild(link);
};

const loadDriveBrowserBundle = (): Promise<DriveBrowserBundle> => {
  if (window.DocSyncWPDriveBrowserBundle) {
    return Promise.resolve(window.DocSyncWPDriveBrowserBundle);
  }

  if (loadPromise) {
    return loadPromise;
  }

  const config = getAdminConfig();

  loadPromise = new Promise((resolve, reject) => {
    if (!config.driveBrowserScriptUrl) {
      reject(new Error(__('Google Drive browser assets are not built.', 'brasth-document-sync-for-google-docs')));
      return;
    }

    config.driveBrowserStyleUrls.forEach((href, index) => ensureLazyStyle(href, `docsync-wp-drive-browser-style-${index}`));

    const existingScript = document.getElementById('docsync-wp-drive-browser-script') as HTMLScriptElement | null;
    const resolveIfReady = () => {
      if (window.DocSyncWPDriveBrowserBundle) {
        resolve(window.DocSyncWPDriveBrowserBundle);
        return true;
      }

      return false;
    };

    if (resolveIfReady()) {
      return;
    }

    window.addEventListener('docsync-wp-drive-browser-ready', () => {
      if (!resolveIfReady()) {
        reject(new Error(__('Google Drive browser did not register correctly.', 'brasth-document-sync-for-google-docs')));
      }
    }, { once: true });

    if (existingScript) {
      existingScript.addEventListener('error', () => {
        reject(new Error(__('Could not load Google Drive browser assets.', 'brasth-document-sync-for-google-docs')));
      }, { once: true });
      return;
    }

    const script = document.createElement('script');

    script.id = 'docsync-wp-drive-browser-script';
    script.async = true;
    script.src = config.driveBrowserScriptUrl;
    script.addEventListener('error', () => {
      reject(new Error(__('Could not load Google Drive browser assets.', 'brasth-document-sync-for-google-docs')));
    }, { once: true });
    document.body.appendChild(script);
  });

  return loadPromise;
};

export const useLazyDriveBrowserPanel = (enabled: boolean) => {
  const [Component, setComponent] = useState<DriveBrowserComponent | null>(() => window.DocSyncWPDriveBrowserBundle?.DriveBrowserPanel ?? null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!enabled || Component) {
      return;
    }

    let active = true;

    setLoading(true);
    setError('');

    loadDriveBrowserBundle()
      .then((bundle) => {
        if (active) {
          setComponent(() => bundle.DriveBrowserPanel);
        }
      })
      .catch((caught) => {
        if (active) {
          setError(caught instanceof Error ? caught.message : __('Could not load Google Drive browser.', 'brasth-document-sync-for-google-docs'));
        }
      })
      .finally(() => {
        if (active) {
          setLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, [enabled, Component]);

  return { Component, error, loading };
};
