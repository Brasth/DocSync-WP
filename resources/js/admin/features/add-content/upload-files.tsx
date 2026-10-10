/**
 * Upload tab: private uploads with real byte progress, server conversion progress,
 * per-file status, the Keep it synced switch for Word files, and Google write access
 * for DOCX and PPTX conversions. Nothing here creates a post or a media item.
 */
import * as Dialog from '@radix-ui/react-dialog';
import { createElement, Fragment, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { ImportFile } from '../../api/journey-types';
import { FormatBadge, Icon, InlineNotice, Switch } from './add-content-dialog';
import {
  fileCanPreview,
  formatBytes,
  formatCount,
  type ImportSessionState,
  type LocalUpload,
  type PdfRenderState,
  useStableId
} from './use-add-content';

const TEXT_DOMAIN = 'brasth-document-sync-for-google-docs';
const ACCEPT = '.docx,.pptx,.pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/pdf';

type Tone = 'progress' | 'ready' | 'attention' | 'failed';

type Progress = { tone: Tone; percent: number | null; label: string };

const attentionCount = (file: ImportFile): number => file.warnings.filter((warning) => warning.severity === 'warning').length;

const conversionLabel = (file: ImportFile): Progress => {
  const progress = file.conversionProgress;
  const percent = progress && progress.total > 0 ? Math.round((progress.done / progress.total) * 100) : null;

  if (!progress) {
    return { tone: 'progress', percent: null, label: __('Converting…', TEXT_DOMAIN) };
  }

  if (progress.step === 'uploading') {
    return { tone: 'progress', percent, label: percent === null ? __('Sending to Google…', TEXT_DOMAIN) : sprintf(__('Sending to Google · %d%%', TEXT_DOMAIN), percent) };
  }

  if (progress.step === 'thumbnails') {
    return {
      tone: 'progress',
      percent,
      label: sprintf(
        /* translators: 1: slides done, 2: total slides. */
        __('Slide images · %1$d of %2$d', TEXT_DOMAIN),
        progress.done,
        progress.total
      )
    };
  }

  if (progress.step === 'extracting') {
    return { tone: 'progress', percent, label: percent === null ? __('Reading content…', TEXT_DOMAIN) : sprintf(__('Reading content · %d%%', TEXT_DOMAIN), percent) };
  }

  return { tone: 'progress', percent, label: percent === null ? __('Converting…', TEXT_DOMAIN) : sprintf(__('Converting · %d%%', TEXT_DOMAIN), percent) };
};

export const serverFileProgress = (file: ImportFile, render: PdfRenderState | undefined): Progress => {
  switch (file.status) {
    case 'converting':
      return conversionLabel(file);
    case 'awaitingGoogleWrite':
      return { tone: 'attention', percent: 0, label: __('Needs Google Drive access', TEXT_DOMAIN) };
    case 'needsRender': {
      if (render?.state === 'error') {
        return { tone: 'failed', percent: 0, label: __('Page images failed', TEXT_DOMAIN) };
      }

      const total = render?.total || file.pendingRenders.length;
      const done = render?.done ?? 0;

      return {
        tone: 'progress',
        percent: total > 0 ? Math.round((done / total) * 100) : null,
        label: sprintf(
          /* translators: 1: pages rendered, 2: pages to render. */
          __('Rendering pages · %1$d of %2$d', TEXT_DOMAIN),
          done,
          total
        )
      };
    }
    case 'ready':
      return attentionCount(file) > 0
        ? { tone: 'attention', percent: 100, label: __('Preview before creating', TEXT_DOMAIN) }
        : { tone: 'ready', percent: 100, label: __('Ready to preview', TEXT_DOMAIN) };
    case 'failed':
      return { tone: 'failed', percent: 100, label: __('Could not convert', TEXT_DOMAIN) };
    case 'committing':
      return { tone: 'progress', percent: null, label: __('Creating draft…', TEXT_DOMAIN) };
    case 'committed':
      return { tone: 'ready', percent: 100, label: __('Draft created', TEXT_DOMAIN) };
    default:
      return { tone: 'progress', percent: 0, label: __('Skipped', TEXT_DOMAIN) };
  }
};

/** "1.8 MB · 14 pages · 2,340 words · 6 images", from server statistics only. */
export const fileFacts = (file: ImportFile): string => {
  const facts = [formatBytes(file.byteSize)];

  if (file.sourceCount.pages) {
    facts.push(sprintf(_n('%s page', '%s pages', file.sourceCount.pages, TEXT_DOMAIN), formatCount(file.sourceCount.pages)));
  }

  if (file.sourceCount.slides) {
    facts.push(sprintf(_n('%s slide', '%s slides', file.sourceCount.slides, TEXT_DOMAIN), formatCount(file.sourceCount.slides)));
  }

  if (file.statistics) {
    facts.push(sprintf(_n('%s word', '%s words', file.statistics.words, TEXT_DOMAIN), formatCount(file.statistics.words)));

    if (file.statistics.images > 0) {
      facts.push(sprintf(_n('%s image', '%s images', file.statistics.images, TEXT_DOMAIN), formatCount(file.statistics.images)));
    }
  }

  if (file.format === 'pptx' && file.detection?.hasNotes) {
    facts.push(__('speaker notes found', TEXT_DOMAIN));
  }

  return facts.join(' · ');
};

const ProgressCell = ({ progress }: { progress: Progress }): JSX.Element => (
  <div className={`dj-progress dj-progress--${progress.tone}`}>
    <div
      aria-label={progress.label}
      aria-valuemax={100}
      aria-valuemin={0}
      aria-valuenow={progress.percent ?? undefined}
      className="dj-progress__track"
      role="progressbar"
    >
      <div
        className={`dj-progress__bar${progress.percent === null ? ' dj-progress__bar--indeterminate' : ''}`}
        style={progress.percent === null ? undefined : { width: `${progress.percent}%` }}
      />
    </div>
    <span className="dj-progress__label">{progress.label}</span>
  </div>
);

const LocalUploadRow = ({ upload, imports }: { upload: LocalUpload; imports: ImportSessionState }): JSX.Element => {
  const percent = upload.total > 0 ? Math.min(100, Math.round((upload.loaded / upload.total) * 100)) : 0;
  const failed = upload.status === 'failed' || upload.status === 'rejected';
  const progress: Progress = upload.status === 'uploading'
    ? { tone: 'progress', percent, label: sprintf(__('Uploading · %d%%', TEXT_DOMAIN), percent) }
    : upload.status === 'queued'
      ? { tone: 'progress', percent: 0, label: __('Waiting to upload', TEXT_DOMAIN) }
      : { tone: 'failed', percent: 100, label: upload.status === 'rejected' ? __('Not accepted', TEXT_DOMAIN) : __('Upload failed', TEXT_DOMAIN) };

  return (
    <div className={`dj-file${failed ? ' dj-file--failed' : ''}`}>
      {upload.format ? <FormatBadge format={upload.format} /> : <span aria-hidden="true" className="dj-row__icon dj-row__icon--folder"><Icon name="doc" /></span>}
      <div style={{ minWidth: 0 }}>
        <div className="dj-file__name">{upload.file.name}</div>
        <div className="dj-file__meta">{failed ? upload.error : formatBytes(upload.file.size)}</div>
        {upload.status === 'failed' ? (
          <div className="dj-file__actions">
            <button className="dj-link" onClick={() => imports.retryUpload(upload.localId)} type="button">{__('Retry', TEXT_DOMAIN)}</button>
          </div>
        ) : null}
      </div>
      <ProgressCell progress={progress} />
      <button
        aria-label={upload.status === 'uploading'
          ? sprintf(__('Cancel upload of %s', TEXT_DOMAIN), upload.file.name)
          : sprintf(__('Remove %s', TEXT_DOMAIN), upload.file.name)}
        className="dj-icon-button"
        onClick={() => imports.cancelUpload(upload.localId)}
        type="button"
      >
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
  );
};

const ServerFileRow = ({ file, imports }: { file: ImportFile; imports: ImportSessionState }): JSX.Element => {
  const render = imports.renders[file.fileId];
  const progress = serverFileProgress(file, render);
  const checks = attentionCount(file);
  const tone = file.status === 'failed' || render?.state === 'error'
    ? 'failed'
    : file.status === 'awaitingGoogleWrite' || (file.status === 'ready' && checks > 0) ? 'attention' : '';
  const optionError = imports.optionErrors[file.fileId];
  const meta = file.status === 'failed'
    ? file.error?.message || __('This file could not be converted.', TEXT_DOMAIN)
    : render?.state === 'error'
      ? render.error
      : file.status === 'awaitingGoogleWrite'
        ? __('Word and PowerPoint files are converted with Google. Allow Document Sync to create files in your Drive to continue.', TEXT_DOMAIN)
        : checks > 0 && file.status === 'ready'
          ? `${fileFacts(file)} · ${sprintf(_n('%d thing to check', '%d things to check', checks, TEXT_DOMAIN), checks)}`
          : fileFacts(file);

  return (
    <div className={`dj-file${tone ? ` dj-file--${tone}` : ''}`}>
      <FormatBadge format={file.format} />
      <div style={{ minWidth: 0 }}>
        <div className="dj-file__name">{file.originalName}</div>
        <div className="dj-file__meta">{meta}</div>
        {optionError ? <div className="dj-file__meta" role="alert">{optionError}</div> : null}
        {file.status === 'awaitingGoogleWrite' ? (
          <div className="dj-file__actions">
            <button className="dj-link" onClick={() => void imports.requestDriveFileAccess()} type="button">{__('Allow Google Drive access', TEXT_DOMAIN)}</button>
          </div>
        ) : null}
        {render?.state === 'error' ? (
          <div className="dj-file__actions">
            <button className="dj-link" onClick={() => imports.retryRender(file.fileId)} type="button">{__('Retry', TEXT_DOMAIN)}</button>
          </div>
        ) : null}
      </div>
      <ProgressCell progress={progress} />
      <button
        aria-label={sprintf(__('Remove %s from this import', TEXT_DOMAIN), file.originalName)}
        className="dj-icon-button"
        disabled={imports.committing}
        onClick={() => imports.excludeFile(file.fileId)}
        type="button"
      >
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
  );
};

type Props = {
  imports: ImportSessionState;
  onCancel: () => void;
  onPreview: () => void;
};

export const UploadFiles = ({ imports, onCancel, onPreview }: Props): JSX.Element => {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [dragging, setDragging] = useState(false);
  const syncId = useStableId('dj-keep-synced');
  const docxFiles = imports.includedFiles.filter((file) => file.format === 'docx');
  const keepSynced = docxFiles.length === 0 ? true : docxFiles.every((file) => file.options.docx?.keepSynced !== false);
  const total = imports.includedFiles.length + imports.uploads.filter((upload) => upload.status === 'queued' || upload.status === 'uploading').length;
  const previewable = imports.includedFiles.filter(fileCanPreview).length;
  const limitBytes = formatBytes(imports.limits.maxFileBytes).replace('.0 ', ' ');
  const folderName = imports.session?.googleWrite.importFolderName ?? 'Imported from WordPress';

  const pick = (files: FileList | null) => {
    if (files) {
      imports.addFiles(Array.from(files));
    }
  };

  return (
    <>
      <div className="dj-upload">
        <div className="dj-upload__main">
          <div
            className={`dj-drop${dragging ? ' dj-drop--active' : ''}`}
            onDragLeave={() => setDragging(false)}
            onDragOver={(event) => {
              event.preventDefault();
              event.dataTransfer.dropEffect = 'copy';
              setDragging(true);
            }}
            onDrop={(event) => {
              event.preventDefault();
              setDragging(false);
              pick(event.dataTransfer.files);
            }}
          >
            <span aria-hidden="true" className="dj-drop__icon"><Icon name="upload" size={22} /></span>
            <div className="dj-drop__copy">
            <strong>{__('Drop files here', TEXT_DOMAIN)}</strong>
            <p>
              {__('or ', TEXT_DOMAIN)}
              <button className="dj-link" disabled={imports.committing} onClick={() => inputRef.current?.click()} type="button">
                {__('choose from your computer', TEXT_DOMAIN)}
              </button>
              {sprintf(
                /* translators: %s: maximum size per file, such as 25 MB. */
                __(' · Word, PowerPoint or PDF · up to %s each', TEXT_DOMAIN),
                limitBytes
              )}
            </p>
            </div>
            <input
              accept={ACCEPT}
              hidden
              multiple
              onChange={(event) => {
                pick(event.currentTarget.files);
                event.currentTarget.value = '';
              }}
              ref={inputRef}
              type="file"
            />
          </div>

          {imports.loadingSession ? (
            <div className="dj-table__state" role="status"><span aria-hidden="true" className="dj-spinner" /> {__('Loading your uploads…', TEXT_DOMAIN)}</div>
          ) : null}
          {imports.sessionError ? <InlineNotice tone="error">{imports.sessionError}</InlineNotice> : null}
          {imports.scopeError ? (
            <InlineNotice actionLabel={__('Allow Google Drive access', TEXT_DOMAIN)} onAction={() => void imports.requestDriveFileAccess()} tone="warning">
              {imports.scopeError}
            </InlineNotice>
          ) : null}

          <div aria-label={__('Files in this import', TEXT_DOMAIN)} aria-live="polite" role="list" style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {imports.includedFiles.map((file) => (
              <div key={file.fileId} role="listitem"><ServerFileRow file={file} imports={imports} /></div>
            ))}
            {imports.uploads.map((upload) => (
              <div key={upload.localId} role="listitem"><LocalUploadRow imports={imports} upload={upload} /></div>
            ))}
          </div>
        </div>

        <aside aria-label={__('What converts well', TEXT_DOMAIN)} className="dj-upload__aside">
          <h3 className="dj-eyebrow">{__('What converts well', TEXT_DOMAIN)}</h3>
          <div className="dj-format-card">
            <FormatBadge format="docx" />
            <div>
              <strong>{__('Word documents — great', TEXT_DOMAIN)}</strong>
              <span>{__('Headings, lists, images, tables and links come through as blocks.', TEXT_DOMAIN)}</span>
            </div>
          </div>
          <div className="dj-format-card">
            <FormatBadge format="pptx" />
            <div>
              <strong>{__('Presentations — one slide per section', TEXT_DOMAIN)}</strong>
              <span>{__('Slide title → heading, bullets → list, slide image → image. Notes optional.', TEXT_DOMAIN)}</span>
            </div>
          </div>
          <div className="dj-format-card dj-format-card--attention">
            <FormatBadge format="pdf" />
            <div>
              <strong>{__('PDFs — text and images only', TEXT_DOMAIN)}</strong>
              <span>{__('Columns, headings and tables are guessed. Always preview. Scanned PDFs aren\'t supported.', TEXT_DOMAIN)}</span>
            </div>
          </div>

          <div className="dj-sync-card">
            <Switch
              checked={keepSynced}
              describedBy={`${syncId}-desc`}
              disabled={docxFiles.length === 0 || imports.committing || imports.optionsPending}
              labelledBy={`${syncId}-label`}
              onChange={(checked) => void imports.updateAllOptions({ docx: { keepSynced: checked } }, (file) => file.format === 'docx')}
            />
            <div>
              <strong id={`${syncId}-label`}>{__('Keep it synced', TEXT_DOMAIN)}</strong>
              <p id={`${syncId}-desc`}>{__('Save a copy to your Google Drive as a Google Doc and link it, so edits there update the post. Word files only.', TEXT_DOMAIN)}</p>
              <p className="dj-sync-card__path">
                {__('Saves to ', TEXT_DOMAIN)}
                <span className="dj-sync-card__path-folder">
                  {sprintf(
                    /* translators: %s: Google Drive folder name. */
                    __('My Drive / %s', TEXT_DOMAIN),
                    folderName
                  )}
                </span>
              </p>
            </div>
          </div>
        </aside>
      </div>

      <div className="dj-footer">
        <span aria-live="polite" className="dj-footer__status dj-footer__status--start">
          <strong>{sprintf(_n('%d file', '%d files', total, TEXT_DOMAIN), total)}</strong>
          {__(' · each becomes its own draft. Nothing is published.', TEXT_DOMAIN)}
        </span>
        {imports.session?.status === 'open' && imports.session.files.length > 0 ? (
          <button className="dj-button" disabled={imports.committing} onClick={onCancel} type="button">{__('Cancel', TEXT_DOMAIN)}</button>
        ) : (
          <Dialog.Close asChild>
            <button className="dj-button" type="button">{__('Cancel', TEXT_DOMAIN)}</button>
          </Dialog.Close>
        )}
        <button
          className="dj-button dj-button--primary"
          disabled={previewable === 0}
          onClick={onPreview}
          type="button"
        >
          {sprintf(
            /* translators: %d: number of files to preview. */
            _n('Preview %d import →', 'Preview %d imports →', imports.includedFiles.length, TEXT_DOMAIN),
            imports.includedFiles.length
          )}
        </button>
      </div>
    </>
  );
};
