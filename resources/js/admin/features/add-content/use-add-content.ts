/**
 * State for the Journey 2 Add content dialog: URL-backed view and session state, the
 * Google connection gate, the multi-Doc selection and batch, the private upload session
 * (byte-progress uploads, conversion polling, per-file options, previews, PDF.js renders),
 * and the commit. Server data is the only source of truth for every count and status.
 */
import { speak } from '@wordpress/a11y';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { inspectDocument, listSharedDrives, type DocumentMetadata, type SyncResult } from '../../api';
import { AdminApiError, REQUEST_ABORTED_CODE } from '../../api/client';
import {
  attachExistingSource,
  cancelImportSession,
  commitImportSession,
  createIdempotencyKey,
  createImportSession,
  createSourceBatch,
  getContinuationAuthUrl,
  getImportFilePreview,
  getImportSession,
  getJourneyGoogleAccount,
  importAssetUrl,
  listImportSessions,
  searchJourneyDriveItems,
  storeRenderedImportAsset,
  updateImportFileOptions,
  uploadImportFiles
} from '../../api/journey-api';
import type {
  ImportCommitResult,
  ImportFile,
  ImportFileOptionsPatch,
  ImportFormat,
  ImportPreview,
  ImportSession,
  JourneyDriveItem,
  JourneyGoogleAccount,
  OAuthReturnTo,
  SourceBatchItem
} from '../../api/journey-types';
import { getAdminConfig } from '../../config';
import type { DocSourceTarget } from '../doc-source-modal/use-doc-source-modal';
import { cropFromPending } from './pdf-render-geometry';
import type { DocSyncWPPdfRenderer, PdfRendererDocument } from './pdf-renderer';

/* ------------------------------------------------------------------ */
/* Shared constants and helpers                                        */
/* ------------------------------------------------------------------ */

export type AddContentView = 'google' | 'upload' | 'preview' | 'deck';

export const MAX_BATCH_DOCS = 20;
export const MAX_IMPORT_FILES = 20;
export const DEFAULT_MAX_FILE_BYTES = 26214400;
const POLL_INTERVAL_MS = 2500;
const DRIVE_PAGE_SIZE = 25;
const TEXT_DOMAIN = 'brasth-document-sync-for-google-docs';

const QUERY_VIEW = 'docsync_view';
const QUERY_SESSION = 'docsync_session';
const QUERY_FILE = 'docsync_file';
const QUERY_RESUME = 'docsync_resume';
const QUERY_RESUME_ID = 'docsync_resume_id';
const QUERY_OAUTH = 'docsync_oauth';
const QUERY_OAUTH_SCOPE = 'docsync_oauth_scope';
const RETURN_MARKER_KEY = 'docsync-wp-add-content-return';
const UUID_PATTERN = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;
const FILE_ID_PATTERN = /^f_[a-f0-9]{16}$/;

const ADD_CONTENT_VIEWS: readonly AddContentView[] = ['google', 'upload', 'preview', 'deck'];

export const errorMessage = (caught: unknown, fallback: string): string => {
  return caught instanceof Error && caught.message ? caught.message : fallback;
};

export const formatBytes = (bytes: number): string => {
  if (bytes >= 1048576) {
    return sprintf(__('%s MB', TEXT_DOMAIN), (bytes / 1048576).toFixed(1));
  }

  return sprintf(__('%s KB', TEXT_DOMAIN), String(Math.max(1, Math.round(bytes / 1024))));
};

export const formatCount = (value: number): string => new Intl.NumberFormat().format(value);

/** "Today", "Yesterday", or a short date such as "Oct 3" (year added outside the current year). */
export const formatEditedDate = (iso: string): string => {
  const date = new Date(iso);

  if (!iso || Number.isNaN(date.getTime())) {
    return '';
  }

  const now = new Date();
  const startOf = (value: Date) => new Date(value.getFullYear(), value.getMonth(), value.getDate()).getTime();
  const days = Math.round((startOf(now) - startOf(date)) / 86400000);

  if (days === 0) {
    return __('Today', TEXT_DOMAIN);
  }

  if (days === 1) {
    return __('Yesterday', TEXT_DOMAIN);
  }

  return new Intl.DateTimeFormat(undefined, date.getFullYear() === now.getFullYear()
    ? { month: 'short', day: 'numeric' }
    : { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
};

export const fileFormatOf = (name: string): ImportFormat | null => {
  const extension = name.toLowerCase().split('.').pop() ?? '';

  return extension === 'docx' || extension === 'pptx' || extension === 'pdf' ? extension : null;
};

let stableIdCounter = 0;

/** Stable DOM ID for label and description wiring (no dependency on React's useId). */
export const useStableId = (prefix: string): string => {
  const [id] = useState(() => {
    stableIdCounter += 1;
    return `${prefix}-${stableIdCounter}`;
  });

  return id;
};

export const titleWithoutExtension = (name: string): string => name.replace(/\.(docx|pptx|pdf)$/i, '');

/* ------------------------------------------------------------------ */
/* URL-backed state and OAuth return                                   */
/* ------------------------------------------------------------------ */

export type AddContentUrlState = {
  view: AddContentView | null;
  sessionId: string | null;
  fileId: string | null;
};

export type OAuthReturnState = {
  connected: boolean;
  driveFileDenied: boolean;
  continuationInvalid: boolean;
  resumeKind: 'import' | 'matching' | null;
  resumeId: string | null;
};

export const readAddContentUrlState = (): AddContentUrlState => {
  const params = new URL(window.location.href).searchParams;
  const view = params.get(QUERY_VIEW);
  const sessionId = params.get(QUERY_SESSION);
  const fileId = params.get(QUERY_FILE);

  return {
    view: ADD_CONTENT_VIEWS.find((candidate) => candidate === view) ?? null,
    sessionId: sessionId && UUID_PATTERN.test(sessionId) ? sessionId : null,
    fileId: fileId && FILE_ID_PATTERN.test(fileId) ? fileId : null
  };
};

const replaceUrl = (mutate: (url: URL) => void) => {
  const url = new URL(window.location.href);
  const before = url.toString();

  mutate(url);

  if (url.toString() !== before) {
    window.history.replaceState(window.history.state, '', url.toString());
  }
};

export const writeAddContentUrlState = (state: AddContentUrlState | null) => {
  replaceUrl((url) => {
    const values: Record<string, string | null> = {
      [QUERY_VIEW]: state?.view ?? null,
      [QUERY_SESSION]: state?.sessionId ?? null,
      [QUERY_FILE]: state?.fileId ?? null
    };

    Object.entries(values).forEach(([key, value]) => {
      if (value) {
        url.searchParams.set(key, value);
      } else {
        url.searchParams.delete(key);
      }
    });
  });
};

/**
 * Read and strip the OAuth callback arguments. The browser never sees tokens: the
 * callback only carries the allowlisted resume kind and the caller-owned record ID.
 */
export const consumeOAuthReturn = (): OAuthReturnState => {
  const params = new URL(window.location.href).searchParams;
  const resumeKind = params.get(QUERY_RESUME);
  const resumeId = params.get(QUERY_RESUME_ID);
  const state: OAuthReturnState = {
    connected: params.get(QUERY_OAUTH) === 'connected',
    driveFileDenied: params.get(QUERY_OAUTH_SCOPE) === 'drive_file_denied',
    continuationInvalid: params.get(QUERY_OAUTH) === 'continuation_invalid',
    resumeKind: resumeKind === 'import' || resumeKind === 'matching' ? resumeKind : null,
    resumeId: resumeId && UUID_PATTERN.test(resumeId) ? resumeId : null
  };

  replaceUrl((url) => {
    [QUERY_RESUME, QUERY_RESUME_ID, QUERY_OAUTH, QUERY_OAUTH_SCOPE].forEach((key) => url.searchParams.delete(key));
  });

  return state;
};

type ReturnMarker = { view: AddContentView; postType: string; createdAt: number };

/** Remember which screen started a read-only connect, so the return lands there again. */
export const rememberAddContentReturn = (view: AddContentView, postType: string) => {
  try {
    window.sessionStorage.setItem(RETURN_MARKER_KEY, JSON.stringify({ view, postType, createdAt: Date.now() }));
  } catch {
    // Storage can be disabled; the user then reopens the dialog manually.
  }
};

export const takeAddContentReturn = (): ReturnMarker | null => {
  try {
    const raw = window.sessionStorage.getItem(RETURN_MARKER_KEY);

    window.sessionStorage.removeItem(RETURN_MARKER_KEY);

    if (!raw) {
      return null;
    }

    const marker = JSON.parse(raw) as Partial<ReturnMarker>;

    if (!marker.view || !ADD_CONTENT_VIEWS.includes(marker.view) || typeof marker.createdAt !== 'number' || Date.now() - marker.createdAt > 3600000) {
      return null;
    }

    return { view: marker.view, postType: typeof marker.postType === 'string' ? marker.postType : '', createdAt: marker.createdAt };
  } catch {
    return null;
  }
};

/** Allowlisted OAuth return page for the current admin screen (contracts section 6.2). */
export const currentReturnTo = (): OAuthReturnTo => {
  const page = new URL(window.location.href).searchParams.get('page');

  return page === 'brasth-document-sync-for-google-docs' ? 'setup' : 'sources';
};

export const isSourcesPage = (): boolean => {
  return new URL(window.location.href).searchParams.get('page') === 'brasth-document-sync-for-google-docs-sources';
};

export const sourcesPageUrl = (): string => 'admin.php?page=brasth-document-sync-for-google-docs-sources';

/* ------------------------------------------------------------------ */
/* Network-lazy PDF renderer loader (contracts section 11.1)           */
/* ------------------------------------------------------------------ */

let pdfRendererPromise: Promise<DocSyncWPPdfRenderer> | null = null;

/**
 * Inject the `pdf-renderer` bundle once and resolve its global. A failed load is
 * dropped so Retry injects the script again.
 */
export const loadPdfRenderer = (scriptUrl: string): Promise<DocSyncWPPdfRenderer> => {
  if (window.DocSyncWPPdfRenderer?.version === 1) {
    return Promise.resolve(window.DocSyncWPPdfRenderer);
  }

  if (pdfRendererPromise) {
    return pdfRendererPromise;
  }

  pdfRendererPromise = new Promise<DocSyncWPPdfRenderer>((resolve, reject) => {
    if (!scriptUrl) {
      reject(new Error(__('The PDF renderer is not built on this site.', TEXT_DOMAIN)));
      return;
    }

    const script = document.createElement('script');

    script.async = true;
    script.src = scriptUrl;
    script.dataset.docsyncPdfRenderer = '1';
    script.addEventListener('load', () => {
      const renderer = window.DocSyncWPPdfRenderer;

      if (renderer?.version === 1) {
        resolve(renderer);
        return;
      }

      script.remove();
      reject(new Error(__('The PDF renderer did not start.', TEXT_DOMAIN)));
    }, { once: true });
    script.addEventListener('error', () => {
      script.remove();
      reject(new Error(__('Could not load the PDF renderer.', TEXT_DOMAIN)));
    }, { once: true });
    document.body.appendChild(script);
  }).catch((caught: unknown) => {
    pdfRendererPromise = null;
    throw caught;
  });

  return pdfRendererPromise;
};

export const pdfRendererScriptUrl = (): string => {
  const value = (window.DocSyncWPAdmin as Record<string, unknown> | undefined)?.pdfRendererScriptUrl;

  return typeof value === 'string' ? value : '';
};

/** Render width that satisfies the server PNG checks: 200–2400 px wide, at most 3400 px tall. */
export const pdfRenderWidth = (widthPt: number, heightPt: number): number | null => {
  if (widthPt <= 0 || heightPt <= 0) {
    return null;
  }

  let width = Math.min(2400, Math.max(200, Math.round(widthPt * 2)));

  if (width * (heightPt / widthPt) > 3400) {
    width = Math.floor(3400 * (widthPt / heightPt));
  }

  return width >= 200 ? width : null;
};

/* ------------------------------------------------------------------ */
/* Google connection gate                                              */
/* ------------------------------------------------------------------ */

export const useGoogleAccountGate = (isOpen: boolean) => {
  const [account, setAccount] = useState<JourneyGoogleAccount | null>(null);
  const [error, setError] = useState('');
  const [connecting, setConnecting] = useState(false);

  const reload = useCallback(async () => {
    setError('');

    try {
      setAccount(await getJourneyGoogleAccount());
    } catch (caught) {
      setError(errorMessage(caught, __('Could not read your Google connection.', TEXT_DOMAIN)));
    }
  }, []);

  useEffect(() => {
    if (isOpen) {
      void reload();
    }
  }, [isOpen, reload]);

  const connect = async (view: AddContentView, postType: string) => {
    setConnecting(true);
    setError('');

    try {
      const returnTo = currentReturnTo();
      const response = await getContinuationAuthUrl({ scopeSet: 'readonly', returnTo });

      rememberAddContentReturn(view, postType);
      window.location.assign(response.authUrl);
    } catch (caught) {
      setError(errorMessage(caught, __('Could not start the Google connection.', TEXT_DOMAIN)));
      setConnecting(false);
    }
  };

  return {
    account,
    connected: Boolean(account?.connected && account.hasRequiredScope),
    connect,
    connecting,
    error,
    loaded: account !== null || error !== '',
    reload
  };
};

/* ------------------------------------------------------------------ */
/* Google Docs selection and batch                                     */
/* ------------------------------------------------------------------ */

export type DriveChip = 'recent' | 'myDrive' | 'sharedDrive' | 'sharedWithMe';

export type DriveFolderCrumb = { folderId: string; driveId: string; name: string };

export type SelectedDoc = {
  fileId: string;
  name: string;
  webViewLink: string;
  modifiedTime: string;
  ownerLabel: string;
};

export type VerifiedDoc = DocumentMetadata & { ownerLabel: string };

export type DriveRow =
  | { kind: 'folder'; id: string; name: string; folderId: string; driveId: string; modifiedTime: string; ownerLabel: string }
  | { kind: 'document'; id: string; item: JourneyDriveItem; ownerLabel: string; blocked: boolean; linked: boolean };

export type LayoutChoice = { kind: 'gutenberg'; presetId: string } | { kind: 'elementor'; presetId: string };

export const encodeLayoutChoice = (choice: LayoutChoice): string => `${choice.kind}:${choice.presetId}`;

export const decodeLayoutChoice = (value: string): LayoutChoice => {
  const [kind, ...rest] = value.split(':');
  const presetId = rest.join(':');

  return kind === 'elementor' ? { kind: 'elementor', presetId } : { kind: 'gutenberg', presetId };
};

export const elementorChoiceAvailable = (): boolean => {
  const config = getAdminConfig();

  return Boolean(config.elementorAvailable && config.elementorSyncEnabled && config.availableElementorLayoutPresets.length > 0);
};

const ownerLabelOf = (item: JourneyDriveItem): string => {
  if (item.ownedByMe) {
    return __('You', TEXT_DOMAIN);
  }

  return item.ownerDisplayName ?? '';
};

const docRowFromItem = (item: JourneyDriveItem): DriveRow => {
  if (item.itemType === 'folder') {
    return {
      kind: 'folder',
      id: `folder:${item.fileId}`,
      name: item.name,
      folderId: item.fileId,
      driveId: '',
      modifiedTime: item.modifiedTime,
      ownerLabel: ownerLabelOf(item)
    };
  }

  return {
    kind: 'document',
    id: item.fileId,
    item,
    ownerLabel: ownerLabelOf(item),
    blocked: item.syncCompatibility?.canDownload === false || !item.selectable,
    linked: item.linked === true
  };
};

type GoogleArgs = {
  isOpen: boolean;
  enabled: boolean;
  target: DocSourceTarget | null;
  onCompleted: (result: SyncResult) => void;
  onLinkedAll: () => void;
};

export const useGoogleSelection = ({ isOpen, enabled, target, onCompleted, onLinkedAll }: GoogleArgs) => {
  const config = useMemo(() => getAdminConfig(), []);
  const singleDoc = target?.mode === 'existing';
  const [urlInput, setUrlInput] = useState('');
  const [checking, setChecking] = useState(false);
  const [urlError, setUrlError] = useState('');
  const [verified, setVerified] = useState<VerifiedDoc[]>([]);
  const [selected, setSelected] = useState<SelectedDoc[]>([]);
  const [failures, setFailures] = useState<Record<string, string>>({});
  const [chip, setChip] = useState<DriveChip>('recent');
  const [folderStack, setFolderStack] = useState<DriveFolderCrumb[]>([]);
  const [search, setSearch] = useState('');
  const [appliedSearch, setAppliedSearch] = useState('');
  const [rows, setRows] = useState<DriveRow[]>([]);
  const [nextPageToken, setNextPageToken] = useState('');
  const [incompleteSearch, setIncompleteSearch] = useState(false);
  const [listing, setListing] = useState(false);
  const [listError, setListError] = useState('');
  const [postType, setPostType] = useState(target?.mode === 'new' ? target.postType : 'post');
  const [layout, setLayout] = useState<string>(() => {
    if (target?.mode === 'existing' && target.elementorSync && elementorChoiceAvailable()) {
      return encodeLayoutChoice({ kind: 'elementor', presetId: target.elementorPreset ?? '' });
    }

    return encodeLayoutChoice({ kind: 'gutenberg', presetId: target?.mode === 'existing' ? target.layoutPreset ?? '' : '' });
  });
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [progress, setProgress] = useState('');
  const [ownershipTransferRequired, setOwnershipTransferRequired] = useState(false);
  const listGeneration = useRef(0);

  useEffect(() => {
    if (!isOpen) {
      setUrlInput('');
      setUrlError('');
      setVerified([]);
      setSelected([]);
      setFailures({});
      setChip('recent');
      setFolderStack([]);
      setSearch('');
      setAppliedSearch('');
      setRows([]);
      setNextPageToken('');
      setSubmitError('');
      setProgress('');
      setOwnershipTransferRequired(false);
    }
  }, [isOpen]);

  useEffect(() => {
    if (target?.mode === 'new') {
      setPostType(target.postType);
    }
  }, [target]);

  // Debounce Drive-wide search so typing does not issue one request per key.
  useEffect(() => {
    const timer = window.setTimeout(() => setAppliedSearch(search.trim()), 350);

    return () => window.clearTimeout(timer);
  }, [search]);

  const currentFolder = folderStack[folderStack.length - 1] ?? null;

  const loadPage = useCallback(async (pageToken = '') => {
    const generation = ++listGeneration.current;
    const append = pageToken !== '';

    setListing(true);
    setListError('');

    try {
      if (!appliedSearch && chip === 'sharedDrive' && !currentFolder) {
        const response = await listSharedDrives({ pageToken: pageToken || undefined, pageSize: DRIVE_PAGE_SIZE });

        if (generation !== listGeneration.current) {
          return;
        }

        const driveRows: DriveRow[] = response.drives.map((drive) => ({
          kind: 'folder',
          id: `drive:${drive.driveId}`,
          name: drive.name,
          folderId: drive.driveId,
          driveId: drive.driveId,
          modifiedTime: '',
          ownerLabel: ''
        }));

        setRows((current) => append ? [...current, ...driveRows] : driveRows);
        setNextPageToken(response.nextPageToken ?? '');
        setIncompleteSearch(false);
        return;
      }

      // Inside a folder the parent decides; a shared drive also needs its drive ID.
      const location = appliedSearch || (currentFolder && chip !== 'sharedDrive')
        ? 'myDrive'
        : chip;
      const response = await searchJourneyDriveItems({
        location,
        folderId: appliedSearch ? '' : currentFolder?.folderId ?? '',
        driveId: appliedSearch ? '' : currentFolder?.driveId ?? '',
        search: appliedSearch,
        globalSearch: appliedSearch !== '',
        linked: 'any',
        pageToken,
        pageSize: DRIVE_PAGE_SIZE
      });

      if (generation !== listGeneration.current) {
        return;
      }

      const nextRows = response.items.map(docRowFromItem).map((row) => {
        if (row.kind === 'folder' && chip === 'sharedDrive' && currentFolder) {
          return { ...row, driveId: currentFolder.driveId };
        }

        return row;
      });

      setRows((current) => append ? [...current, ...nextRows] : nextRows);
      setNextPageToken(response.nextPageToken ?? '');
      setIncompleteSearch(Boolean(response.incompleteSearch));
    } catch (caught) {
      if (generation === listGeneration.current) {
        setListError(errorMessage(caught, __('Could not list Google Drive.', TEXT_DOMAIN)));
      }
    } finally {
      if (generation === listGeneration.current) {
        setListing(false);
      }
    }
  }, [appliedSearch, chip, currentFolder]);

  useEffect(() => {
    if (!isOpen || !enabled) {
      return;
    }

    setRows([]);
    setNextPageToken('');
    void loadPage('');
  }, [isOpen, enabled, loadPage]);

  const changeChip = (next: DriveChip) => {
    setChip(next);
    setFolderStack([]);
    setSearch('');
    setAppliedSearch('');
  };

  const openFolder = (row: Extract<DriveRow, { kind: 'folder' }>) => {
    setSearch('');
    setAppliedSearch('');
    setFolderStack((current) => [...current, { folderId: row.folderId, driveId: row.driveId, name: row.name }]);
  };

  const goToFolder = (index: number) => {
    setFolderStack((current) => current.slice(0, index + 1));
  };

  const isSelected = (fileId: string) => selected.some((doc) => doc.fileId === fileId);

  const addSelection = (doc: SelectedDoc): boolean => {
    if (isSelected(doc.fileId)) {
      return true;
    }

    if (singleDoc) {
      setSelected([doc]);
      return true;
    }

    if (selected.length >= MAX_BATCH_DOCS) {
      const message = sprintf(
        /* translators: %d: maximum number of Google Docs per batch. */
        __('Select up to %d Google Docs at a time.', TEXT_DOMAIN),
        MAX_BATCH_DOCS
      );

      setSubmitError(message);
      speak(message, 'assertive');
      return false;
    }

    setSelected((current) => [...current, doc]);
    return true;
  };

  const removeSelection = (fileId: string) => {
    setSelected((current) => current.filter((doc) => doc.fileId !== fileId));
    setFailures((current) => {
      const next = { ...current };
      delete next[fileId];
      return next;
    });
  };

  const toggleRow = (row: Extract<DriveRow, { kind: 'document' }>) => {
    if (row.blocked || row.linked) {
      return;
    }

    setSubmitError('');

    if (isSelected(row.item.fileId)) {
      removeSelection(row.item.fileId);
      setVerified((current) => current.filter((doc) => doc.fileId !== row.item.fileId));
      return;
    }

    addSelection({
      fileId: row.item.fileId,
      name: row.item.name,
      webViewLink: row.item.webViewLink,
      modifiedTime: row.item.modifiedTime,
      ownerLabel: row.ownerLabel
    });
  };

  const checkUrl = async () => {
    const value = urlInput.trim();

    if (!value) {
      return;
    }

    setChecking(true);
    setUrlError('');

    try {
      const metadata = await inspectDocument(value, /^https?:\/\//i.test(value) ? 'url' : 'file_id');

      if (metadata.syncCompatibility?.canDownload === false) {
        setUrlError(metadata.syncCompatibility.warningMessage || __('Google says this Doc cannot be downloaded by your account. Ask the owner for access.', TEXT_DOMAIN));
        return;
      }

      const doc: VerifiedDoc = { ...metadata, ownerLabel: '' };
      const added = addSelection({
        fileId: metadata.fileId,
        name: metadata.name,
        webViewLink: metadata.webViewLink,
        modifiedTime: metadata.modifiedTime,
        ownerLabel: ''
      });

      if (added) {
        setVerified((current) => singleDoc
          ? [doc]
          : [...current.filter((item) => item.fileId !== doc.fileId), doc]);
        setUrlInput('');
        speak(sprintf(__('%s checked and selected.', TEXT_DOMAIN), metadata.name));
      }
    } catch (caught) {
      const message = errorMessage(caught, __('Could not check this Google Doc link.', TEXT_DOMAIN));

      setUrlError(message);
      speak(message, 'assertive');
    } finally {
      setChecking(false);
    }
  };

  const removeVerified = (fileId: string) => {
    setVerified((current) => current.filter((doc) => doc.fileId !== fileId));
    removeSelection(fileId);
  };

  const layoutChoice = decodeLayoutChoice(layout);
  const useElementor = layoutChoice.kind === 'elementor' && elementorChoiceAvailable();

  const createDrafts = async () => {
    if (!target || target.mode !== 'new' || selected.length === 0) {
      return;
    }

    const docs = selected.slice(0, MAX_BATCH_DOCS);
    const items: SourceBatchItem[] = docs.map((doc) => ({
      fileId: doc.fileId,
      target: { mode: 'new', postType, postStatus: 'draft' },
      syncMode: 'background',
      layoutPreset: useElementor ? '' : layoutChoice.presetId,
      elementorSync: useElementor,
      elementorPreset: useElementor ? layoutChoice.presetId : ''
    }));

    setSubmitting(true);
    setSubmitError('');
    setProgress(sprintf(
      /* translators: %d: number of drafts being created. */
      _n('Creating %d draft…', 'Creating %d drafts…', docs.length, TEXT_DOMAIN),
      docs.length
    ));

    try {
      const response = await createSourceBatch(createIdempotencyKey(), items);
      const succeeded = new Set<string>();
      const failed: Record<string, string> = {};

      response.results.forEach((result) => {
        const doc = docs[result.index];

        if (!doc) {
          return;
        }

        if (result.status === 'failed') {
          failed[doc.fileId] = result.error?.message || __('Could not create this draft.', TEXT_DOMAIN);
          return;
        }

        succeeded.add(doc.fileId);

        if (result.postId) {
          onCompleted({
            postId: result.postId,
            status: result.status,
            changed: false,
            created: true,
            queued: result.status === 'queued',
            source: result.source
          });
        }
      });

      // Successes leave the selection; only failures stay, with their reasons.
      setSelected((current) => current.filter((doc) => !succeeded.has(doc.fileId)));
      setVerified((current) => current.filter((doc) => !succeeded.has(doc.fileId)));
      setFailures(failed);

      const failedCount = Object.keys(failed).length;

      if (failedCount === 0) {
        speak(sprintf(_n('%d draft created.', '%d drafts created.', succeeded.size, TEXT_DOMAIN), succeeded.size));
        onLinkedAll();
        return;
      }

      const message = sprintf(
        /* translators: 1: created drafts, 2: failed Docs. */
        __('%1$d created, %2$d could not be added. The failed Docs stay selected below.', TEXT_DOMAIN),
        succeeded.size,
        failedCount
      );

      setSubmitError(message);
      speak(message, 'assertive');
    } catch (caught) {
      const message = errorMessage(caught, __('Could not create these drafts.', TEXT_DOMAIN));

      setSubmitError(message);
      speak(message, 'assertive');
    } finally {
      setSubmitting(false);
      setProgress('');
    }
  };

  const linkExisting = async (transferOwnership = false) => {
    if (!target || target.mode !== 'existing' || selected.length !== 1) {
      return;
    }

    const doc = selected[0];

    setSubmitting(true);
    setSubmitError('');

    try {
      const result = await attachExistingSource({
        fileId: doc.fileId,
        postId: target.postId,
        transferOwnership,
        elementorSync: elementorChoiceAvailable() ? useElementor : undefined,
        layoutPreset: useElementor ? undefined : layoutChoice.presetId,
        elementorPreset: useElementor ? layoutChoice.presetId : undefined
      });

      setOwnershipTransferRequired(false);
      onCompleted(result);
      onLinkedAll();
    } catch (caught) {
      if (caught instanceof AdminApiError && caught.code === 'docsync_wp_source_owner_transfer_required') {
        setOwnershipTransferRequired(true);
        return;
      }

      const message = errorMessage(caught, __('Could not link this Google Doc.', TEXT_DOMAIN));

      setFailures({ [doc.fileId]: message });
      setSubmitError(message);
      speak(message, 'assertive');
    } finally {
      setSubmitting(false);
    }
  };

  return {
    canSubmit: selected.length > 0 && !submitting,
    changeChip,
    checkUrl,
    checking,
    chip,
    config,
    createDrafts,
    currentFolder,
    failures,
    folderStack,
    goToFolder,
    incompleteSearch,
    isSelected,
    layout,
    linkExisting,
    listError,
    listing,
    loadMore: () => loadPage(nextPageToken),
    hasMore: nextPageToken !== '',
    openFolder,
    ownershipTransferRequired,
    postType,
    progress,
    removeSelection,
    removeVerified,
    retryList: () => loadPage(''),
    rows,
    search,
    selected,
    setLayout,
    setOwnershipTransferRequired,
    setPostType,
    setSearch,
    setUrlInput,
    singleDoc,
    submitError,
    submitting,
    toggleRow,
    urlError,
    urlInput,
    useElementor,
    verified
  };
};

export type GoogleSelection = ReturnType<typeof useGoogleSelection>;

/* ------------------------------------------------------------------ */
/* Upload session                                                      */
/* ------------------------------------------------------------------ */

export type LocalUpload = {
  localId: string;
  file: File;
  format: ImportFormat | null;
  status: 'queued' | 'uploading' | 'failed' | 'rejected';
  loaded: number;
  total: number;
  error: string;
};

export type PdfRenderState = {
  state: 'idle' | 'loading' | 'rendering' | 'error';
  done: number;
  total: number;
  error: string;
};

type PreviewEntry = { preview: ImportPreview; loadedAt: number };

const excludedStorageKey = (sessionId: string) => `docsync-wp-import-excluded-${sessionId}`;
const commitKeyStorageKey = (sessionId: string) => `docsync-wp-import-commit-${sessionId}`;

const readStoredList = (key: string): string[] => {
  try {
    const parsed = JSON.parse(window.sessionStorage.getItem(key) ?? '[]') as unknown;

    return Array.isArray(parsed) ? parsed.filter((value): value is string => typeof value === 'string') : [];
  } catch {
    return [];
  }
};

const writeStoredValue = (key: string, value: string | null) => {
  try {
    if (value === null) {
      window.sessionStorage.removeItem(key);
    } else {
      window.sessionStorage.setItem(key, value);
    }
  } catch {
    // Storage may be disabled; state then lives for this page view only.
  }
};

export const fileIsBusy = (file: ImportFile): boolean => file.status === 'converting' || file.status === 'committing';

export const fileCanPreview = (file: ImportFile): boolean => file.status === 'ready' || file.status === 'needsRender';

type ImportArgs = {
  isOpen: boolean;
  initialSessionId: string | null;
  defaultPostType: string;
  onImported: (result: ImportCommitResult) => void;
};

export const useImportSession = ({ isOpen, initialSessionId, defaultPostType, onImported }: ImportArgs) => {
  const [session, setSession] = useState<ImportSession | null>(null);
  const [sessionError, setSessionError] = useState('');
  const [loadingSession, setLoadingSession] = useState(false);
  const [uploads, setUploads] = useState<LocalUpload[]>([]);
  const [excluded, setExcluded] = useState<string[]>([]);
  const [previews, setPreviews] = useState<Record<string, PreviewEntry>>({});
  const [previewErrors, setPreviewErrors] = useState<Record<string, string>>({});
  const [pendingOptions, setPendingOptions] = useState<Record<string, number>>({});
  const [optionErrors, setOptionErrors] = useState<Record<string, string>>({});
  const [renders, setRenders] = useState<Record<string, PdfRenderState>>({});
  const [committing, setCommitting] = useState(false);
  const [commitError, setCommitError] = useState('');
  const [scopeError, setScopeError] = useState('');
  const sessionRef = useRef<ImportSession | null>(null);
  const creating = useRef<Promise<ImportSession> | null>(null);
  const uploadController = useRef<AbortController | null>(null);
  const uploadingId = useRef<string | null>(null);
  const renderDocuments = useRef<Record<string, PdfRendererDocument>>({});
  const renderingFiles = useRef<Set<string>>(new Set());
  const previewRequests = useRef<Record<string, string>>({});
  const reportedResult = useRef<string>('');

  const applySession = useCallback((next: ImportSession | null) => {
    sessionRef.current = next;
    setSession(next);

    if (next) {
      setExcluded(readStoredList(excludedStorageKey(next.sessionId)));
    }
  }, []);

  /* Load or resume the session when the dialog opens. */
  useEffect(() => {
    if (!isOpen) {
      return;
    }

    let active = true;

    const resume = async () => {
      setLoadingSession(true);
      setSessionError('');

      try {
        if (initialSessionId) {
          const loaded = await getImportSession(initialSessionId);

          if (active) {
            applySession(loaded);
          }

          return;
        }

        const sessions = await listImportSessions();
        const open = sessions.find((summary) => summary.status === 'open' && summary.fileCount > 0)
          ?? sessions.find((summary) => summary.status === 'committing');

        if (open && active) {
          applySession(await getImportSession(open.sessionId));
        }
      } catch (caught) {
        if (!active) {
          return;
        }

        // A missing route means Journey 2 is not available on this site yet.
        if (caught instanceof AdminApiError && (caught.code === 'docsync_wp_import_session_not_found' || caught.code === 'docsync_wp_import_session_expired')) {
          applySession(null);
          setSessionError(caught.code === 'docsync_wp_import_session_expired'
            ? __('That import expired after 24 hours and its files were deleted. Upload the files again.', TEXT_DOMAIN)
            : '');
          return;
        }

        setSessionError(errorMessage(caught, __('Could not load your uploads.', TEXT_DOMAIN)));
      } finally {
        if (active) {
          setLoadingSession(false);
        }
      }
    };

    void resume();

    return () => {
      active = false;
    };
  }, [isOpen, initialSessionId, applySession]);

  /* Reset when the dialog closes; uploads in flight are cancelled. */
  useEffect(() => {
    if (isOpen) {
      return;
    }

    uploadController.current?.abort();
    Object.values(renderDocuments.current).forEach((document) => void document.destroy());
    renderDocuments.current = {};
    renderingFiles.current.clear();
    applySession(null);
    setUploads([]);
    setPreviews({});
    setPreviewErrors({});
    setPendingOptions({});
    setOptionErrors({});
    setRenders({});
    setCommitError('');
    setScopeError('');
    setSessionError('');
  }, [isOpen, applySession]);

  const refreshSession = useCallback(async () => {
    const current = sessionRef.current;

    if (!current) {
      return null;
    }

    try {
      const next = await getImportSession(current.sessionId);

      applySession(next);
      return next;
    } catch (caught) {
      if (caught instanceof AdminApiError && (caught.code === 'docsync_wp_import_session_not_found' || caught.code === 'docsync_wp_import_session_expired')) {
        applySession(null);
        setSessionError(caught.code === 'docsync_wp_import_session_expired'
          ? __('This import expired after 24 hours and its files were deleted.', TEXT_DOMAIN)
          : __('This import is no longer available.', TEXT_DOMAIN));
        return null;
      }

      setSessionError(errorMessage(caught, __('Could not refresh your uploads.', TEXT_DOMAIN)));
      return null;
    }
  }, [applySession]);

  /* Poll while the server converts files or commits. */
  const needsPolling = Boolean(session && (
    session.status === 'committing'
    || session.files.some((file) => fileIsBusy(file))
  ));

  useEffect(() => {
    if (!isOpen || !needsPolling) {
      return;
    }

    const timer = window.setInterval(() => {
      void refreshSession();
    }, POLL_INTERVAL_MS);

    return () => window.clearInterval(timer);
  }, [isOpen, needsPolling, refreshSession]);

  /* Report the commit result once it finishes. */
  useEffect(() => {
    if (!session?.result?.finishedAt || session.status !== 'committed') {
      return;
    }

    const key = `${session.sessionId}:${session.result.idempotencyKey}`;

    if (reportedResult.current === key) {
      return;
    }

    reportedResult.current = key;
    writeStoredValue(commitKeyStorageKey(session.sessionId), null);
    setCommitting(false);
    onImported(session.result);

    const created = session.result.files.filter((file) => file.status === 'created').length;
    const failed = session.result.files.filter((file) => file.status === 'failed').length;

    speak(failed > 0
      ? sprintf(__('%1$d drafts created, %2$d failed.', TEXT_DOMAIN), created, failed)
      : sprintf(_n('%d draft created.', '%d drafts created.', created, TEXT_DOMAIN), created));
  }, [session, onImported]);

  const ensureSession = async (): Promise<ImportSession> => {
    if (sessionRef.current && sessionRef.current.status === 'open') {
      return sessionRef.current;
    }

    if (!creating.current) {
      creating.current = createImportSession().then((created) => {
        applySession(created);
        return created;
      }).finally(() => {
        creating.current = null;
      });
    }

    return creating.current;
  };

  const limits = session?.limits ?? { maxFiles: MAX_IMPORT_FILES, maxFileBytes: DEFAULT_MAX_FILE_BYTES, remainingFiles: MAX_IMPORT_FILES };

  /** Validate and queue picked files; rejected ones stay listed with their reason. */
  const addFiles = (picked: File[]) => {
    if (picked.length === 0) {
      return;
    }

    setSessionError('');

    const inFlight = uploads.filter((upload) => upload.status === 'queued' || upload.status === 'uploading').length;
    let remaining = limits.remainingFiles - inFlight;
    const queued: LocalUpload[] = picked.map((file, index) => {
      const format = fileFormatOf(file.name);
      const base = {
        localId: `${Date.now()}-${index}-${file.name}`,
        file,
        format,
        loaded: 0,
        total: file.size
      };

      if (!format) {
        return { ...base, status: 'rejected', error: __('Only Word (.docx), PowerPoint (.pptx), and PDF files can be imported.', TEXT_DOMAIN) };
      }

      if (file.size > limits.maxFileBytes) {
        return {
          ...base,
          status: 'rejected',
          error: sprintf(
            /* translators: %s: maximum file size such as 25.0 MB. */
            __('This file is larger than %s.', TEXT_DOMAIN),
            formatBytes(limits.maxFileBytes)
          )
        };
      }

      if (file.size === 0) {
        return { ...base, status: 'rejected', error: __('This file is empty.', TEXT_DOMAIN) };
      }

      if (remaining <= 0) {
        return {
          ...base,
          status: 'rejected',
          error: sprintf(
            /* translators: %d: maximum number of files per import. */
            __('An import holds at most %d files.', TEXT_DOMAIN),
            limits.maxFiles
          )
        };
      }

      remaining -= 1;
      return { ...base, status: 'queued', error: '' };
    });

    setUploads((current) => [...current, ...queued]);
  };

  /* Upload queued files one at a time so each row shows its own byte progress. */
  useEffect(() => {
    if (!isOpen || uploadingId.current) {
      return;
    }

    const next = uploads.find((upload) => upload.status === 'queued');

    if (!next) {
      return;
    }

    uploadingId.current = next.localId;
    const controller = new AbortController();
    uploadController.current = controller;

    const update = (patch: Partial<LocalUpload>) => {
      setUploads((current) => current.map((upload) => upload.localId === next.localId ? { ...upload, ...patch } : upload));
    };

    update({ status: 'uploading', loaded: 0, error: '' });

    const run = async () => {
      try {
        const target = await ensureSession();
        const response = await uploadImportFiles(target.sessionId, [next.file], {
          signal: controller.signal,
          onProgress: ({ loaded, total }) => update({ loaded, total: total || next.file.size })
        });

        applySession(response.session);

        if (response.rejected.length > 0) {
          update({ status: 'rejected', error: response.rejected[0].message });
          return;
        }

        const addedId = response.added[0];

        // A new file adopts the session's default target and the dialog's post type.
        if (addedId) {
          const added = response.session.files.find((file) => file.fileId === addedId);

          if (added && defaultPostType && added.options.target.postType !== defaultPostType) {
            void updateImportFileOptions(response.session.sessionId, addedId, { target: { postType: defaultPostType } })
              .then((file) => mergeFile(file))
              .catch(() => undefined);
          }
        }

        setUploads((current) => current.filter((upload) => upload.localId !== next.localId));
      } catch (caught) {
        if (caught instanceof AdminApiError && caught.code === REQUEST_ABORTED_CODE) {
          setUploads((current) => current.filter((upload) => upload.localId !== next.localId));
          return;
        }

        if (caught instanceof AdminApiError && caught.code === 'docsync_wp_import_session_limit') {
          update({ status: 'failed', error: __('You already have 3 imports in progress. Finish or cancel one, then retry.', TEXT_DOMAIN) });
          return;
        }

        if (caught instanceof AdminApiError && ['docsync_wp_import_session_not_open', 'docsync_wp_import_session_not_found', 'docsync_wp_import_session_expired'].includes(caught.code)) {
          applySession(null);
        }

        update({ status: 'failed', error: errorMessage(caught, __('Upload failed.', TEXT_DOMAIN)) });
      } finally {
        uploadingId.current = null;
        uploadController.current = null;
        setUploads((current) => [...current]);
      }
    };

    void run();
    // ensureSession and mergeFile read refs; the queue only reacts to upload changes.
  }, [isOpen, uploads]);

  const cancelUpload = (localId: string) => {
    if (uploadingId.current === localId) {
      uploadController.current?.abort();
      return;
    }

    setUploads((current) => current.filter((upload) => upload.localId !== localId));
  };

  const retryUpload = (localId: string) => {
    setUploads((current) => current.map((upload) => upload.localId === localId ? { ...upload, status: 'queued', error: '', loaded: 0 } : upload));
  };

  const mergeFile = (file: ImportFile) => {
    const current = sessionRef.current;

    if (!current) {
      return;
    }

    applySession({
      ...current,
      files: current.files.map((item) => item.fileId === file.fileId ? file : item)
    });
  };

  /** Exclude a server file from this import (it ends as `skipped` at commit). */
  const excludeFile = (fileId: string) => {
    const current = sessionRef.current;

    if (!current) {
      return;
    }

    const next = Array.from(new Set([...excluded, fileId]));

    setExcluded(next);
    writeStoredValue(excludedStorageKey(current.sessionId), JSON.stringify(next));
  };

  const includedFiles = useMemo(() => {
    return (session?.files ?? []).filter((file) => !excluded.includes(file.fileId) && file.status !== 'skipped');
  }, [session, excluded]);

  /* Options */

  const updateOptions = async (fileId: string, patch: ImportFileOptionsPatch): Promise<boolean> => {
    const current = sessionRef.current;

    if (!current) {
      return false;
    }

    setPendingOptions((value) => ({ ...value, [fileId]: (value[fileId] ?? 0) + 1 }));
    setOptionErrors((value) => {
      const next = { ...value };
      delete next[fileId];
      return next;
    });

    try {
      mergeFile(await updateImportFileOptions(current.sessionId, fileId, patch));
      return true;
    } catch (caught) {
      if (caught instanceof AdminApiError && caught.code === 'docsync_wp_google_write_scope_required') {
        setScopeError(caught.message);
      }

      setOptionErrors((value) => ({ ...value, [fileId]: errorMessage(caught, __('Could not save this option.', TEXT_DOMAIN)) }));
      return false;
    } finally {
      setPendingOptions((value) => {
        const next = { ...value };
        const count = (next[fileId] ?? 1) - 1;

        if (count <= 0) {
          delete next[fileId];
        } else {
          next[fileId] = count;
        }

        return next;
      });
    }
  };

  const updateAllOptions = async (patch: ImportFileOptionsPatch, filter: (file: ImportFile) => boolean = () => true) => {
    await Promise.all(includedFiles.filter(filter).map((file) => updateOptions(file.fileId, patch)));
  };

  /* Previews: fetched per file and re-fetched whenever the server fingerprint changes. */

  const loadPreview = useCallback(async (fileId: string, force = false) => {
    const current = sessionRef.current;
    const file = current?.files.find((item) => item.fileId === fileId);

    if (!current || !file || !fileCanPreview(file) || !file.previewFingerprint) {
      return;
    }

    if (!force && previewRequests.current[fileId] === file.previewFingerprint) {
      return;
    }

    previewRequests.current[fileId] = file.previewFingerprint;

    try {
      const preview = await getImportFilePreview(current.sessionId, fileId);

      setPreviews((value) => ({ ...value, [fileId]: { preview, loadedAt: Date.now() } }));
      setPreviewErrors((value) => {
        const next = { ...value };
        delete next[fileId];
        return next;
      });
    } catch (caught) {
      delete previewRequests.current[fileId];
      setPreviewErrors((value) => ({ ...value, [fileId]: errorMessage(caught, __('Could not load this preview.', TEXT_DOMAIN)) }));
    }
  }, []);

  const previewIsCurrent = (file: ImportFile): boolean => {
    const entry = previews[file.fileId];

    return Boolean(entry && file.previewFingerprint && entry.preview.previewFingerprint === file.previewFingerprint);
  };

  /* PDF.js renders for pending pages (contracts sections 3.9 and 11.1). */

  const setRender = (fileId: string, patch: Partial<PdfRenderState>) => {
    setRenders((value) => ({
      ...value,
      [fileId]: { ...(value[fileId] ?? { state: 'idle', done: 0, total: 0, error: '' }), ...patch }
    }));
  };

  const openPdf = async (file: ImportFile): Promise<PdfRendererDocument> => {
    const current = sessionRef.current;

    if (!current || !file.originalAssetId) {
      throw new Error(__('The original PDF is not available.', TEXT_DOMAIN));
    }

    const cached = renderDocuments.current[file.fileId];

    if (cached) {
      return cached;
    }

    const renderer = await loadPdfRenderer(pdfRendererScriptUrl());
    const opened = await renderer.open({ url: importAssetUrl(current.sessionId, file.fileId, file.originalAssetId) });

    renderDocuments.current[file.fileId] = opened;
    return opened;
  };

  const renderPendingPages = useCallback(async (file: ImportFile) => {
    const current = sessionRef.current;

    if (!current || file.status !== 'needsRender' || file.pendingRenders.length === 0 || renderingFiles.current.has(file.fileId)) {
      return;
    }

    renderingFiles.current.add(file.fileId);
    setRender(file.fileId, { state: 'loading', done: 0, total: file.pendingRenders.length, error: '' });

    try {
      const pdf = await openPdf(file);
      let done = 0;

      setRender(file.fileId, { state: 'rendering' });

      for (const pending of file.pendingRenders) {
        const width = pdfRenderWidth(pending.widthPt, pending.heightPt);

        if (width === null) {
          throw new Error(sprintf(__('Page %d is too tall or narrow to render as an image.', TEXT_DOMAIN), pending.page));
        }

        const png = await pdf.renderPagePng(pending.page, width, cropFromPending(pending));
        const response = await storeRenderedImportAsset(current.sessionId, file.fileId, pending.assetId, png);

        done += 1;
        mergeFile(response.file);
        setRender(file.fileId, { done });
      }

      setRender(file.fileId, { state: 'idle', done, error: '' });
      await refreshSession();
    } catch (caught) {
      setRender(file.fileId, {
        state: 'error',
        error: errorMessage(caught, __('Could not render the PDF pages in your browser.', TEXT_DOMAIN))
      });
    } finally {
      renderingFiles.current.delete(file.fileId);
    }
    // openPdf and mergeFile read refs only.
  }, [refreshSession]);

  useEffect(() => {
    if (!isOpen || !session) {
      return;
    }

    session.files
      .filter((file) => !excluded.includes(file.fileId) && file.status === 'needsRender' && file.pendingRenders.length > 0)
      .filter((file) => renders[file.fileId]?.state !== 'error')
      .forEach((file) => void renderPendingPages(file));
  }, [isOpen, session, excluded, renders, renderPendingPages]);

  const retryRender = (fileId: string) => {
    const file = sessionRef.current?.files.find((item) => item.fileId === fileId);

    setRender(fileId, { state: 'idle', error: '' });

    if (file) {
      void renderPendingPages(file);
    }
  };

  /* Google write access for DOCX and PPTX conversions. */

  const requestDriveFileAccess = async () => {
    const current = sessionRef.current;

    setScopeError('');

    try {
      const response = await getContinuationAuthUrl({
        scopeSet: 'driveFile',
        returnTo: currentReturnTo() === 'setup' ? 'sources' : currentReturnTo(),
        resumeKind: current ? 'import' : undefined,
        resumeId: current?.sessionId
      });

      window.location.assign(response.authUrl);
    } catch (caught) {
      setScopeError(errorMessage(caught, __('Could not start the Google permission request.', TEXT_DOMAIN)));
    }
  };

  /* Commit */

  const committable = includedFiles.filter((file) => file.status === 'ready' && file.previewFingerprint);
  const blockingFiles = includedFiles.filter((file) => file.status !== 'ready' && file.status !== 'failed');
  const optionsPending = Object.keys(pendingOptions).length > 0;
  const uploadsPending = uploads.some((upload) => upload.status === 'queued' || upload.status === 'uploading');
  const canCommit = Boolean(
    session?.status === 'open'
    && committable.length > 0
    && blockingFiles.length === 0
    && !optionsPending
    && !uploadsPending
    && !committing
  );

  const commit = async () => {
    const current = sessionRef.current;

    if (!current || !canCommit) {
      return;
    }

    const storedKey = (() => {
      try {
        return window.sessionStorage.getItem(commitKeyStorageKey(current.sessionId));
      } catch {
        return null;
      }
    })();
    const idempotencyKey = storedKey && /^[A-Za-z0-9-]{16,64}$/.test(storedKey) ? storedKey : createIdempotencyKey();

    writeStoredValue(commitKeyStorageKey(current.sessionId), idempotencyKey);
    setCommitting(true);
    setCommitError('');

    try {
      applySession(await commitImportSession(current.sessionId, {
        idempotencyKey,
        files: committable.map((file) => ({ fileId: file.fileId, previewFingerprint: file.previewFingerprint as string }))
      }));
      speak(__('Creating drafts. This can take a minute.', TEXT_DOMAIN));
    } catch (caught) {
      setCommitting(false);

      if (caught instanceof AdminApiError && caught.code === 'docsync_wp_idempotency_conflict') {
        writeStoredValue(commitKeyStorageKey(current.sessionId), null);
      }

      if (caught instanceof AdminApiError && caught.code === 'docsync_wp_import_preview_stale') {
        writeStoredValue(commitKeyStorageKey(current.sessionId), null);
        await refreshSession();
        setCommitError(__('A file changed after its preview. Review the updated preview, then create the drafts again.', TEXT_DOMAIN));
        return;
      }

      const message = errorMessage(caught, __('Could not create the drafts.', TEXT_DOMAIN));

      setCommitError(message);
      speak(message, 'assertive');
    }
  };

  /** Cancel purges the private bytes and trashes app-created Google conversions (section 3.4). */
  const discard = async (): Promise<boolean> => {
    const current = sessionRef.current;

    uploadController.current?.abort();
    setUploads([]);

    if (!current) {
      return true;
    }

    try {
      await cancelImportSession(current.sessionId);
      writeStoredValue(excludedStorageKey(current.sessionId), null);
      writeStoredValue(commitKeyStorageKey(current.sessionId), null);
      applySession(null);
      return true;
    } catch (caught) {
      setSessionError(errorMessage(caught, __('Could not cancel this import.', TEXT_DOMAIN)));
      return false;
    }
  };

  return {
    addFiles,
    blockingFiles,
    canCommit,
    cancelUpload,
    commit,
    commitError,
    committable,
    committing: committing || session?.status === 'committing',
    discard,
    excludeFile,
    includedFiles,
    limits,
    loadPreview,
    loadingSession,
    openPdf,
    optionErrors,
    optionsPending,
    pendingOptions,
    previewErrors,
    previewIsCurrent,
    previews,
    refreshSession,
    renders,
    requestDriveFileAccess,
    retryRender,
    retryUpload,
    scopeError,
    session,
    sessionError,
    updateAllOptions,
    updateOptions,
    uploads,
    uploadsPending
  };
};

export type ImportSessionState = ReturnType<typeof useImportSession>;
