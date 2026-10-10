/**
 * Journey 2 root: one Radix dialog that hosts the Google Docs picker, the connect-first
 * gate, uploads, the import preview, and the deck review. The legacy folder-watch flow
 * stays in DocSourceModal; this dialog only handles Docs and files.
 */
import * as Dialog from '@radix-ui/react-dialog';
import { createElement, useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { SyncResult } from '../../api';
import type { ImportCommitResult, ImportFile, ImportFormat } from '../../api/journey-types';
import { getAdminConfig } from '../../config';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { ensureLazyStyle } from '../doc-source-modal/lazy-drive-browser-panel';
import type { DocSourceTarget } from '../doc-source-modal/use-doc-source-modal';
import { ConnectFirst } from './connect-first';
import { DeckPreview, deckHeaderSummary } from './deck-preview';
import { GooglePicker } from './google-picker';
import { ImportPreviewScreen } from './import-preview';
import { UploadFiles } from './upload-files';
import {
  type AddContentView,
  fileCanPreview,
  isSourcesPage,
  sourcesPageUrl,
  useGoogleAccountGate,
  useGoogleSelection,
  useImportSession,
  writeAddContentUrlState
} from './use-add-content';

const TEXT_DOMAIN = 'brasth-document-sync-for-google-docs';

/* ------------------------------------------------------------------ */
/* Shared primitives used by every Journey 2 screen                    */
/* ------------------------------------------------------------------ */

type IconName = 'doc' | 'folder' | 'lock' | 'upload' | 'person' | 'check' | 'search' | 'back' | 'drive';

const ICON_PATHS: Record<IconName, ReactNode> = {
  doc: (
    <g fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.6">
      <path d="M5.5 2.5h6l3 3v11a1 1 0 0 1-1 1h-8a1 1 0 0 1-1-1v-13a1 1 0 0 1 1-1Z" />
      <path d="M11.5 2.5v3h3M7.5 10h5M7.5 13h5" />
    </g>
  ),
  folder: <path d="M2.5 5.5a1 1 0 0 1 1-1h4l1.5 1.5h7.5a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1Z" fill="none" stroke="currentColor" strokeLinejoin="round" strokeWidth="1.6" />,
  lock: (
    <g fill="none" stroke="currentColor" strokeLinecap="round" strokeWidth="1.6">
      <rect height="7" rx="1" width="10" x="5" y="9" />
      <path d="M7 9V7a3 3 0 0 1 6 0v2" />
    </g>
  ),
  upload: <path d="M10 13V4m0 0L6.5 7.5M10 4l3.5 3.5M4 15.5h12" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.7" />,
  person: (
    <g fill="none" stroke="currentColor" strokeLinecap="round" strokeWidth="1.6">
      <circle cx="10" cy="7" r="3" />
      <path d="M4.5 16.5a5.5 5.5 0 0 1 11 0" />
    </g>
  ),
  check: <path d="m4 10.5 4 4 8-9" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" />,
  search: (
    <g fill="none" stroke="currentColor" strokeLinecap="round" strokeWidth="1.6">
      <circle cx="9" cy="9" r="5" />
      <path d="m13 13 3.5 3.5" />
    </g>
  ),
  back: <path d="M16 10H4m0 0 5-5m-5 5 5 5" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.6" />,
  drive: <path d="M7 3.5h6l4.5 8-3 5h-9l-3-5Z" fill="none" stroke="currentColor" strokeLinejoin="round" strokeWidth="1.5" />
};

export const Icon = ({ name, size = 16 }: { name: IconName; size?: number }): JSX.Element => (
  <svg aria-hidden="true" className="dj-icon" focusable="false" height={size} viewBox="0 0 20 20" width={size}>
    {ICON_PATHS[name]}
  </svg>
);

export const FormatBadge = ({ format, small = false }: { format: ImportFormat; small?: boolean }): JSX.Element => (
  <span aria-hidden="true" className={`dj-badge dj-badge--${format}${small ? ' dj-badge--small' : ''}`}>
    {format.toUpperCase()}
  </span>
);

export const WarningNumber = ({ number }: { number: number }): JSX.Element => (
  <span
    aria-label={sprintf(
      /* translators: %d: warning number. */
      __('Check %d', TEXT_DOMAIN),
      number
    )}
    className="dj-warning-number"
  >
    {number}
  </span>
);

type SelectOption = { value: string; label: string };
type SelectGroup = { label: string; options: SelectOption[] };

export const NativeSelect = ({
  label,
  value,
  options,
  groups,
  disabled = false,
  onChange
}: {
  label: string;
  value: string;
  options?: SelectOption[];
  groups?: SelectGroup[];
  disabled?: boolean;
  onChange: (value: string) => void;
}): JSX.Element => (
  <span className="dj-select">
    <select aria-label={label} disabled={disabled} onChange={(event) => onChange(event.currentTarget.value)} value={value}>
      {(options ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
      {(groups ?? []).map((group) => (
        <optgroup key={group.label} label={group.label}>
          {group.options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </optgroup>
      ))}
    </select>
  </span>
);

export const Switch = ({
  checked,
  disabled = false,
  labelledBy,
  describedBy,
  onChange
}: {
  checked: boolean;
  disabled?: boolean;
  labelledBy: string;
  describedBy?: string;
  onChange: (checked: boolean) => void;
}): JSX.Element => (
  <button
    aria-checked={checked}
    aria-describedby={describedBy}
    aria-labelledby={labelledBy}
    className="dj-switch"
    disabled={disabled}
    onClick={() => onChange(!checked)}
    role="switch"
    type="button"
  />
);

export const InlineNotice = ({
  tone,
  children,
  actionLabel,
  onAction
}: {
  tone: 'error' | 'warning' | 'info';
  children: ReactNode;
  actionLabel?: string;
  onAction?: () => void;
}): JSX.Element => (
  <div className={`dj-notice dj-notice--${tone}`} role={tone === 'error' ? 'alert' : 'status'}>
    {children}
    {actionLabel && onAction ? (
      <button className="dj-link dj-notice__action" onClick={onAction} type="button">{actionLabel}</button>
    ) : null}
  </div>
);

const trimTrailingSlash = (value: string): string => value.replace(/\/$/, '');

export const BrandMark = (): JSX.Element | null => {
  const config = getAdminConfig();

  if (!config.pluginUrl) {
    return null;
  }

  return (
    <img
      alt=""
      aria-hidden="true"
      className="dj-brand__mark"
      height="28"
      src={`${trimTrailingSlash(config.pluginUrl)}/resources/images/brasth-mark.png`}
      width="28"
    />
  );
};

export const CloseButton = (): JSX.Element => (
  <Dialog.Close asChild>
    <button aria-label={__('Close', TEXT_DOMAIN)} className="dj-close" type="button">
      <span aria-hidden="true">&times;</span>
    </button>
  </Dialog.Close>
);

export const BackButton = ({ label, onClick }: { label: string; onClick: () => void }): JSX.Element => (
  <button aria-label={label} className="dj-back" onClick={onClick} type="button">
    <Icon name="back" size={16} />
  </button>
);

type SourceTab = 'google' | 'upload';

const SourceTabs = ({ value, onChange }: { value: SourceTab; onChange: (tab: SourceTab) => void }): JSX.Element => {
  const tabs: { id: SourceTab; label: string }[] = [
    { id: 'google', label: __('Google Docs', TEXT_DOMAIN) },
    { id: 'upload', label: __('Upload a file', TEXT_DOMAIN) }
  ];

  const rootRef = useRef<HTMLDivElement | null>(null);
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;

    return () => {
      mounted.current = false;
    };
  }, []);

  const onKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
      return;
    }

    event.preventDefault();
    const next = value === 'google' ? 'upload' : 'google';
    onChange(next);
    // React clears currentTarget after the handler returns, so read the root now.
    const root = rootRef.current;

    window.setTimeout(() => {
      if (!mounted.current || !root) {
        return;
      }

      root.querySelector<HTMLButtonElement>(`[data-tab="${next}"]:not(:disabled)`)?.focus();
    }, 0);
  };

  return (
    <div aria-label={__('Content source', TEXT_DOMAIN)} className="dj-tabs" onKeyDown={onKeyDown} ref={rootRef} role="tablist">
      {tabs.map((tab) => (
        <button
          aria-selected={value === tab.id}
          className="dj-tab"
          data-tab={tab.id}
          key={tab.id}
          onClick={() => onChange(tab.id)}
          role="tab"
          tabIndex={value === tab.id ? 0 : -1}
          type="button"
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
};

/* ------------------------------------------------------------------ */
/* Dialog                                                              */
/* ------------------------------------------------------------------ */

export type AddContentDialogProps = {
  isOpen: boolean;
  target: DocSourceTarget | null;
  initialView?: AddContentView;
  initialSessionId?: string | null;
  initialFileId?: string | null;
  /** One-time message from an OAuth return, such as a denied Google permission. */
  notice?: string;
  onClose: () => void;
  onCompleted: (result: SyncResult) => void;
  onImported?: (result: ImportCommitResult) => void;
};

type Size = 'google' | 'connect' | 'upload' | 'preview' | 'deck' | 'loading';

export const AddContentDialog = ({
  isOpen,
  target,
  initialView = 'google',
  initialSessionId = null,
  initialFileId = null,
  notice = '',
  onClose,
  onCompleted,
  onImported
}: AddContentDialogProps): JSX.Element | null => {
  const existingTarget = target?.mode === 'existing';
  const canUpload = getAdminConfig().canUploadFiles;
  // Existing posts only attach a Google Doc; uploads need the upload_files capability.
  const importsEnabled = !existingTarget && canUpload;
  const [view, setView] = useState<AddContentView>(importsEnabled ? initialView : 'google');
  const [activeFileId, setActiveFileId] = useState<string | null>(initialFileId);
  const [reviewed, setReviewed] = useState<string[]>([]);
  const [discardOpen, setDiscardOpen] = useState(false);
  const [noticeDismissed, setNoticeDismissed] = useState(false);
  const defaultPostType = target?.mode === 'new' ? target.postType : target?.postType ?? 'post';
  const gate = useGoogleAccountGate(isOpen);

  const finishGoogle = useCallback(() => {
    if (target?.mode === 'new' && !isSourcesPage()) {
      window.location.assign(sourcesPageUrl());
      return;
    }

    onClose();
  }, [onClose, target]);

  const google = useGoogleSelection({
    isOpen,
    enabled: view === 'google' && gate.connected,
    target,
    onCompleted,
    onLinkedAll: finishGoogle
  });

  const handleImported = useCallback((result: ImportCommitResult) => {
    onImported?.(result);
  }, [onImported]);

  const imports = useImportSession({
    isOpen: isOpen && importsEnabled,
    initialSessionId,
    defaultPostType,
    onImported: handleImported
  });

  useEffect(() => {
    if (isOpen) {
      setView(importsEnabled ? initialView : 'google');
      setActiveFileId(initialFileId);
      setReviewed([]);
      setNoticeDismissed(false);
      getAdminConfig().docSourceModalStyleUrls.forEach((href, index) => {
        ensureLazyStyle(href, `docsync-wp-doc-source-modal-style-${index}`);
      });
    }
  }, [isOpen, importsEnabled, initialView, initialFileId]);

  const activeFile: ImportFile | null = useMemo(() => {
    const files = imports.includedFiles;

    return files.find((file) => file.fileId === activeFileId) ?? files.find(fileCanPreview) ?? files[0] ?? null;
  }, [imports.includedFiles, activeFileId]);

  // A deck view without a PPTX file falls back to the preview list.
  const effectiveView: AddContentView = view === 'deck' && activeFile?.format !== 'pptx' ? 'preview' : view;

  /* Keep view, session, and file in the URL so an OAuth round trip lands on the same screen. */
  useEffect(() => {
    if (!isOpen) {
      writeAddContentUrlState(null);
      return;
    }

    writeAddContentUrlState({
      view: effectiveView,
      sessionId: effectiveView === 'google' ? null : imports.session?.sessionId ?? null,
      fileId: effectiveView === 'preview' || effectiveView === 'deck' ? activeFile?.fileId ?? null : null
    });
  }, [isOpen, effectiveView, imports.session?.sessionId, activeFile?.fileId]);

  if (!isOpen || !target) {
    return null;
  }

  const showConnect = effectiveView === 'google' && gate.loaded && !gate.connected && !gate.error;
  const size: Size = effectiveView === 'google'
    ? !gate.loaded ? 'loading' : showConnect ? 'connect' : 'google'
    : effectiveView;
  const changeTab = (tab: SourceTab) => setView(tab);
  const openPreview = (fileId?: string) => {
    const file = imports.includedFiles.find((item) => item.fileId === fileId);

    if (file) {
      setActiveFileId(file.fileId);
    }

    setView('preview');
  };
  const openDeck = (fileId: string) => {
    setActiveFileId(fileId);
    setView('deck');
  };
  const markReviewed = (fileId: string) => {
    setReviewed((current) => current.includes(fileId) ? current : [...current, fileId]);
    setView('preview');
  };
  const requestCancel = () => {
    if (imports.session && imports.session.status === 'open' && imports.session.files.length > 0) {
      setDiscardOpen(true);
      return;
    }

    void imports.discard().then((discarded) => {
      if (discarded) {
        onClose();
      }
    });
  };

  const title = effectiveView === 'preview'
    ? __('Preview imports', TEXT_DOMAIN)
    : effectiveView === 'deck' && activeFile
      ? activeFile.options.title || activeFile.originalName
      : existingTarget ? __('Link a Google Doc', TEXT_DOMAIN) : __('Add content', TEXT_DOMAIN);

  const description = effectiveView === 'preview'
    ? __('Check what each file becomes before any draft is created.', TEXT_DOMAIN)
    : effectiveView === 'deck' && activeFile
      ? deckHeaderSummary(activeFile, imports.previews[activeFile.fileId]?.preview ?? null)
      : effectiveView === 'upload'
        ? __('Upload Word, PowerPoint, or PDF files. Each becomes its own draft.', TEXT_DOMAIN)
        : showConnect
          ? __('Connect your Google account to pick Docs.', TEXT_DOMAIN)
          : existingTarget
            ? __('Linking keeps this post\'s content until its first sync.', TEXT_DOMAIN)
            : __('Each Doc becomes a WordPress draft that stays in sync.', TEXT_DOMAIN);

  const header = (() => {
    if (effectiveView === 'preview' || (effectiveView === 'deck' && activeFile)) {
      return (
        <div className="dj-header dj-header--preview">
          <BackButton
            label={effectiveView === 'deck' ? __('Back to all files', TEXT_DOMAIN) : __('Back to uploads', TEXT_DOMAIN)}
            onClick={() => setView(effectiveView === 'deck' ? 'preview' : 'upload')}
          />
          <div className="dj-deck-title">
            {effectiveView === 'deck' && activeFile ? <FormatBadge format="pptx" /> : null}
            <div className="dj-brand__copy">
              <Dialog.Title asChild><h2 className="dj-title">{title}</h2></Dialog.Title>
              <Dialog.Description asChild><p className="dj-subtitle">{description}</p></Dialog.Description>
            </div>
          </div>
          <CloseButton />
        </div>
      );
    }

    if (effectiveView === 'upload') {
      return (
        <div className="dj-header dj-header--upload">
          <div className="dj-brand">
            <BrandMark />
            <div className="dj-brand__copy">
              <Dialog.Title asChild><h2 className="dj-title">{title}</h2></Dialog.Title>
              <Dialog.Description asChild><p className="screen-reader-text">{description}</p></Dialog.Description>
            </div>
          </div>
          <SourceTabs onChange={changeTab} value="upload" />
          <div className="dj-header__end"><CloseButton /></div>
        </div>
      );
    }

    if (showConnect || size === 'loading') {
      return (
        <div className="dj-header dj-header--connect">
          <div className="dj-brand">
            <BrandMark />
            <div className="dj-brand__copy">
              <Dialog.Title asChild><h2 className="dj-title">{title}</h2></Dialog.Title>
              <Dialog.Description asChild><p className="screen-reader-text">{description}</p></Dialog.Description>
            </div>
          </div>
          <CloseButton />
        </div>
      );
    }

    return (
      <div className="dj-header">
        <div className="dj-brand">
          <BrandMark />
          <div className="dj-brand__copy">
            <Dialog.Title asChild><h2 className="dj-title">{title}</h2></Dialog.Title>
            <Dialog.Description asChild><p className="dj-subtitle">{description}</p></Dialog.Description>
          </div>
        </div>
        <div className="dj-header__end">
          {importsEnabled ? <SourceTabs onChange={changeTab} value="google" /> : null}
          <CloseButton />
        </div>
      </div>
    );
  })();

  const body = (() => {
    if (effectiveView === 'google') {
      if (!gate.loaded) {
        return <div className="dj-state"><span aria-hidden="true" className="dj-spinner" />&nbsp;{__('Checking your Google connection…', TEXT_DOMAIN)}</div>;
      }

      if (gate.error) {
        return (
          <div className="dj-body">
            <InlineNotice actionLabel={__('Retry', TEXT_DOMAIN)} onAction={() => void gate.reload()} tone="error">{gate.error}</InlineNotice>
          </div>
        );
      }

      if (showConnect) {
        return (
          <ConnectFirst
            account={gate.account}
            connecting={gate.connecting}
            error={gate.error}
            onConnect={() => void gate.connect('google', defaultPostType)}
          />
        );
      }

      return <GooglePicker google={google} target={target} />;
    }

    if (effectiveView === 'upload') {
      return (
        <UploadFiles
          imports={imports}
          onCancel={requestCancel}
          onPreview={() => openPreview(activeFile?.fileId)}
        />
      );
    }

    if (effectiveView === 'deck' && activeFile) {
      return (
        <DeckPreview
          file={activeFile}
          imports={imports}
          onBack={() => setView('preview')}
          onLooksGood={() => markReviewed(activeFile.fileId)}
        />
      );
    }

    return (
      <ImportPreviewScreen
        activeFile={activeFile}
        imports={imports}
        onCancel={requestCancel}
        onClose={onClose}
        onOpenDeck={openDeck}
        onSelectFile={setActiveFileId}
        reviewed={reviewed}
      />
    );
  })();

  return (
    <Dialog.Root open={isOpen} onOpenChange={(open) => {
      if (!open) {
        onClose();
      }
    }}>
      <Dialog.Portal>
        <Dialog.Overlay className="docsync-wp-journey-overlay" />
        <Dialog.Content
          aria-busy={imports.committing || google.submitting}
          className={`docsync-wp-journey docsync-wp-journey-dialog docsync-wp-journey-dialog--${size}`}
          onPointerDownOutside={(event) => event.preventDefault()}
        >
          {header}
          {notice && !noticeDismissed ? (
            <div className="dj-resume-notice">
              <InlineNotice actionLabel={__('Dismiss', TEXT_DOMAIN)} onAction={() => setNoticeDismissed(true)} tone="warning">{notice}</InlineNotice>
            </div>
          ) : null}
          {body}
        </Dialog.Content>
      </Dialog.Portal>
      <ConfirmDialog
        busy={false}
        confirmLabel={__('Discard uploads', TEXT_DOMAIN)}
        description={__('Your uploaded files and any Google copies made from them are deleted. No drafts were created.', TEXT_DOMAIN)}
        open={discardOpen}
        title={__('Discard this import?', TEXT_DOMAIN)}
        onConfirm={() => {
          setDiscardOpen(false);
          void imports.discard().then((discarded) => {
            if (discarded) {
              onClose();
            }
          });
        }}
        onOpenChange={setDiscardOpen}
      />
    </Dialog.Root>
  );
};
