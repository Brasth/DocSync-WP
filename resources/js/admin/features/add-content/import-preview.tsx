/**
 * Preview imports: a file navigator, the effective canonical document exactly as the
 * draft will receive it (numbered warnings sit on the blocks they concern), the checks
 * and statistics the server reported, and the commit. Nothing is created until the
 * user confirms, and a changed fingerprint blocks the commit until the preview reloads.
 */
import { createElement, Fragment, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { importAssetUrl } from '../../api/journey-api';
import type {
  Block,
  CanonicalDocument,
  ImportCommitFileResult,
  ImportFile,
  ImportPreview,
  ImportWarning,
  ListItem,
  Origin,
  PdfRenderMode,
  Run,
  Section
} from '../../api/journey-types';
import { getAdminConfig } from '../../config';
import { FormatBadge, InlineNotice, NativeSelect, Switch, WarningNumber } from './add-content-dialog';
import { postTypeDraftOptions } from './google-picker';
import { serverFileProgress } from './upload-files';
import {
  errorMessage,
  fileCanPreview,
  formatCount,
  type ImportSessionState,
  titleWithoutExtension,
  useStableId
} from './use-add-content';

const TEXT_DOMAIN = 'brasth-document-sync-for-google-docs';

/* ------------------------------------------------------------------ */
/* Canonical document rendering                                        */
/* ------------------------------------------------------------------ */

const safeLink = (href: string | undefined): string | null => {
  return href && /^https?:\/\//i.test(href) ? href : null;
};

export const RunsView = ({ runs }: { runs: Run[] }): JSX.Element => (
  <>
    {runs.map((run, index) => {
      let node: ReactNode = run.text;

      if (run.code) {
        node = <code>{node}</code>;
      }

      if (run.bold) {
        node = <strong>{node}</strong>;
      }

      if (run.italic) {
        node = <em>{node}</em>;
      }

      if (run.underline) {
        node = <u>{node}</u>;
      }

      if (run.strike) {
        node = <s>{node}</s>;
      }

      const href = safeLink(run.link);

      if (href) {
        node = <a href={href} rel="noopener noreferrer" target="_blank">{node}</a>;
      }

      return <Fragment key={index}>{node}</Fragment>;
    })}
  </>
);

const ListItemsView = ({ items, ordered }: { items: ListItem[]; ordered: boolean }): JSX.Element => {
  const Tag = ordered ? 'ol' : 'ul';

  return (
    <Tag>
      {items.map((item, index) => (
        <li key={index}>
          <RunsView runs={item.runs} />
          {item.children.length > 0 ? <ListItemsView items={item.children} ordered={ordered} /> : null}
        </li>
      ))}
    </Tag>
  );
};

type AssetContext = { sessionId: string; fileId: string; document: CanonicalDocument };

export const BlockView = ({ block, assets }: { block: Block; assets: AssetContext }): JSX.Element => {
  switch (block.type) {
    case 'heading': {
      const Tag = `h${Math.min(6, Math.max(1, block.level))}` as 'h2';

      return <Tag><RunsView runs={block.runs} /></Tag>;
    }
    case 'list':
      return <ListItemsView items={block.items} ordered={block.ordered} />;
    case 'table':
      return (
        <table>
          <tbody>
            {block.rows.map((row, rowIndex) => (
              <tr key={rowIndex}>
                {row.cells.map((cell, cellIndex) => {
                  const Cell = cell.header ? 'th' : 'td';

                  return (
                    <Cell colSpan={cell.colSpan > 1 ? cell.colSpan : undefined} key={cellIndex} rowSpan={cell.rowSpan > 1 ? cell.rowSpan : undefined}>
                      <RunsView runs={cell.runs} />
                    </Cell>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      );
    case 'image': {
      const asset = assets.document.assets.find((item) => item.assetId === block.assetId);
      const caption = block.caption ? <figcaption>{block.caption}</figcaption> : null;

      if (!asset || asset.status === 'rejected') {
        return <figure><div className="dj-doc__pending">{__('This image could not be imported.', TEXT_DOMAIN)}</div>{caption}</figure>;
      }

      if (asset.status === 'pendingRender') {
        return (
          <figure data-docsync-pending-asset={asset.assetId}>
            <div className="dj-doc__pending">
              {asset.origin.page
                ? sprintf(__('Page %d is being rendered in your browser…', TEXT_DOMAIN), asset.origin.page)
                : __('Rendering…', TEXT_DOMAIN)}
            </div>
            {caption}
          </figure>
        );
      }

      return (
        <figure>
          <img
            alt={block.alt}
            height={asset.height ?? undefined}
            loading="lazy"
            src={importAssetUrl(assets.sessionId, assets.fileId, asset.assetId)}
            width={asset.width ?? undefined}
          />
          {caption}
        </figure>
      );
    }
    default:
      return (
        <p style={block.align && block.align !== 'left' ? { textAlign: block.align } : undefined}>
          <RunsView runs={block.runs} />
        </p>
      );
  }
};

const boundsIntersect = (left: NonNullable<Origin['bounds']>, right: NonNullable<Origin['bounds']>): boolean => {
  return left.x < right.x + right.width
    && right.x < left.x + left.width
    && left.y < right.y + right.height
    && right.y < left.y + left.height;
};

/**
 * Attach each numbered warning to at most one block: fallback images carry their numbers,
 * then Docs indexes, then overlapping bounds on the same page or slide. Warnings with no
 * block match stay in the side list only.
 */
export const mapWarningsToBlocks = (document: CanonicalDocument, warnings: ImportWarning[]): Record<string, number[]> => {
  const assigned: Record<string, number[]> = {};
  const used = new Set<number>();
  const add = (blockId: string, number: number) => {
    if (used.has(number)) {
      return;
    }

    used.add(number);
    assigned[blockId] = [...(assigned[blockId] ?? []), number];
  };
  const blocks = document.sections.flatMap((section) => section.blocks);

  blocks.forEach((block) => {
    if (block.type === 'image' && block.fallback) {
      block.fallback.warningNumbers.forEach((number) => add(block.id, number));
    }
  });

  warnings.forEach((warning) => {
    const origin = warning.origin;

    if (!origin || used.has(warning.number)) {
      return;
    }

    const match = blocks.find((block) => {
      if (typeof origin.docIndex === 'number') {
        return block.origin.docIndex === origin.docIndex;
      }

      const samePlace = (origin.page !== undefined && block.origin.page === origin.page)
        || (origin.slide !== undefined && block.origin.slide === origin.slide);

      return samePlace && Boolean(origin.bounds && block.origin.bounds && boundsIntersect(origin.bounds, block.origin.bounds));
    });

    if (match) {
      add(match.id, warning.number);
    }
  });

  return assigned;
};

export const FlaggedBlock = ({ numbers, children }: { numbers: number[] | undefined; children: ReactNode }): JSX.Element => {
  if (!numbers || numbers.length === 0) {
    return <>{children}</>;
  }

  return (
    <div className="dj-flagged">
      {children}
      <span className="dj-flagged__numbers">
        {numbers.map((number) => <WarningNumber key={number} number={number} />)}
      </span>
    </div>
  );
};

export const SectionView = ({ section, assets, flags }: { section: Section; assets: AssetContext; flags: Record<string, number[]> }): JSX.Element => {
  const blocks = section.blocks.map((block) => (
    <FlaggedBlock key={block.id} numbers={flags[block.id]}>
      <BlockView assets={assets} block={block} />
    </FlaggedBlock>
  ));

  if (section.kind === 'notes') {
    return (
      <div className="dj-doc__notes">
        <p><strong>{__('From the speaker notes', TEXT_DOMAIN)}</strong></p>
        {blocks}
      </div>
    );
  }

  return <>{blocks}</>;
};

/* ------------------------------------------------------------------ */
/* Labels                                                              */
/* ------------------------------------------------------------------ */

export const presetLabel = (presetId: string): string => {
  const config = getAdminConfig();
  const id = presetId || config.defaultLayoutPreset;

  return config.availableLayoutPresets.find((preset) => preset.id === id)?.label ?? id;
};

const warningTitle = (warning: ImportWarning): string => {
  switch (warning.code) {
    case 'unsupportedElement': {
      const element = {
        chart: __('Chart shown as an image', TEXT_DOMAIN),
        video: __('Video shown as an image', TEXT_DOMAIN),
        animation: __('Animation shown as an image', TEXT_DOMAIN),
        wordArt: __('WordArt shown as an image', TEXT_DOMAIN),
        group: __('Grouped shapes shown as an image', TEXT_DOMAIN)
      };

      return warning.element ? element[warning.element] : __('Shown as an image', TEXT_DOMAIN);
    }
    case 'imageSkipped':
      return __('Image left out', TEXT_DOMAIN);
    case 'pageRenderedAsImage':
      return __('Page kept as an image', TEXT_DOMAIN);
    case 'stylingDropped':
      return __('Styling simplified', TEXT_DOMAIN);
    case 'tableSimplified':
      return __('Table rebuilt from layout', TEXT_DOMAIN);
    case 'linkRemoved':
      return __('Link removed', TEXT_DOMAIN);
    case 'pdfTableAsText':
      return __('Table read as text', TEXT_DOMAIN);
    default:
      return __('Empty section', TEXT_DOMAIN);
  }
};

const rangeSummary = (selected: number[], total: number, noun: 'page' | 'slide'): string => {
  const sorted = [...selected].sort((a, b) => a - b);
  const contiguous = sorted.length > 0 && sorted[sorted.length - 1] - sorted[0] === sorted.length - 1;

  if (noun === 'slide') {
    return sprintf(
      /* translators: 1: included slides, 2: total slides. */
      __('%1$d of %2$d slides', TEXT_DOMAIN),
      sorted.length,
      total
    );
  }

  if (sorted.length === total) {
    return sprintf(_n('%d page', '%d pages', total, TEXT_DOMAIN), total);
  }

  if (contiguous) {
    return sprintf(
      /* translators: 1: first page, 2: last page, 3: total pages. */
      __('Page %1$d–%2$d of %3$d', TEXT_DOMAIN),
      sorted[0],
      sorted[sorted.length - 1],
      total
    );
  }

  return sprintf(__('%1$d of %2$d pages', TEXT_DOMAIN), sorted.length, total);
};

export const sourceSummary = (file: ImportFile): string => {
  if (file.format === 'pdf' && file.sourceCount.pages) {
    return rangeSummary(file.options.pdf?.pages ?? [], file.sourceCount.pages, 'page');
  }

  if (file.format === 'pptx' && file.sourceCount.slides) {
    return rangeSummary(file.options.pptx?.slides ?? [], file.sourceCount.slides, 'slide');
  }

  if (file.sourceCount.pages) {
    return sprintf(_n('%d page', '%d pages', file.sourceCount.pages, TEXT_DOMAIN), file.sourceCount.pages);
  }

  return '';
};

const attentionWarnings = (file: ImportFile): number => file.warnings.filter((warning) => warning.severity === 'warning').length;

const navMeta = (file: ImportFile, imports: ImportSessionState, result: ImportCommitFileResult | undefined): { text: string; tone: string } => {
  if (result) {
    if (result.status === 'created') {
      return { text: __('Draft created', TEXT_DOMAIN), tone: '' };
    }

    if (result.status === 'failed') {
      return { text: result.error?.message || __('Could not create this draft', TEXT_DOMAIN), tone: 'failed' };
    }

    return { text: __('Not created', TEXT_DOMAIN), tone: 'muted' };
  }

  if (!fileCanPreview(file)) {
    const progress = serverFileProgress(file, imports.renders[file.fileId]);

    return { text: file.status === 'failed' ? file.error?.message || progress.label : progress.label, tone: file.status === 'failed' ? 'failed' : 'muted' };
  }

  const checks = attentionWarnings(file);

  if (file.format === 'pptx' && file.statistics) {
    return {
      text: sprintf(
        /* translators: 1: slide count, 2: section count. */
        __('%1$d slides → %2$d sections', TEXT_DOMAIN),
        file.options.pptx?.slides.length ?? file.sourceCount.slides ?? 0,
        file.statistics.sections
      ),
      tone: checks > 0 ? 'attention' : ''
    };
  }

  if (checks > 0) {
    return { text: sprintf(_n('%d thing to check', '%d things to check', checks, TEXT_DOMAIN), checks), tone: 'attention' };
  }

  if (file.format === 'docx') {
    return {
      text: `${presetLabel(file.options.layoutPreset)} · ${file.options.docx?.keepSynced === false ? __('one-time import', TEXT_DOMAIN) : __('synced copy on', TEXT_DOMAIN)}`,
      tone: ''
    };
  }

  return { text: presetLabel(file.options.layoutPreset), tone: '' };
};

/* ------------------------------------------------------------------ */
/* PDF page picker (PDF.js, network-lazy)                              */
/* ------------------------------------------------------------------ */

const PdfPageThumb = ({ page, open, selected, onToggle }: {
  page: number;
  open: () => Promise<{ renderThumbnail: (page: number, canvas: HTMLCanvasElement, maxWidthPx: number) => Promise<void> }>;
  selected: boolean;
  onToggle: () => void;
}): JSX.Element => {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const [drawn, setDrawn] = useState(false);

  useEffect(() => {
    const canvas = canvasRef.current;

    if (!canvas || drawn) {
      return;
    }

    let active = true;
    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) {
        return;
      }

      observer.disconnect();
      void open()
        .then((pdf) => pdf.renderThumbnail(page, canvas, 72 * (window.devicePixelRatio || 1)))
        .then(() => {
          if (active) {
            setDrawn(true);
          }
        })
        .catch(() => undefined);
    });

    observer.observe(canvas);

    return () => {
      active = false;
      observer.disconnect();
    };
  }, [open, page, drawn]);

  return (
    <button
      aria-label={sprintf(__('Page %d', TEXT_DOMAIN), page)}
      aria-pressed={selected}
      className="dj-page"
      onClick={onToggle}
      type="button"
    >
      <canvas height={96} ref={canvasRef} style={{ width: 72 }} width={72} />
      <span>{page}</span>
    </button>
  );
};

const PdfOptionsPanel = ({ file, imports }: { file: ImportFile; imports: ImportSessionState }): JSX.Element | null => {
  const total = file.sourceCount.pages ?? 0;
  const [pages, setPages] = useState<number[]>(file.options.pdf?.pages ?? []);
  const [loadError, setLoadError] = useState('');
  const [attempt, setAttempt] = useState(0);
  const modeId = useStableId('dj-pdf-mode');
  const currentPages = file.options.pdf?.pages ?? [];
  const renderMode: PdfRenderMode = file.options.pdf?.renderMode ?? 'auto';
  const changed = pages.length !== currentPages.length || pages.some((page) => !currentPages.includes(page));
  const busy = Boolean(imports.pendingOptions[file.fileId]) || file.status === 'converting';

  useEffect(() => {
    setPages(file.options.pdf?.pages ?? []);
  }, [file.fileId, file.options.pdf?.pages]);

  const openRef = useRef(imports.openPdf);

  openRef.current = imports.openPdf;

  const stableOpen = useCallback(() => openRef.current(file).catch((caught: unknown) => {
    setLoadError(errorMessage(caught, __('Could not load the PDF renderer.', TEXT_DOMAIN)));
    throw caught;
  }), [file.fileId, attempt]);

  if (!file.originalAssetId || total === 0) {
    return null;
  }

  return (
    <div className="dj-aside__field">
      <span className="dj-aside__label">{__('Pages to import', TEXT_DOMAIN)}</span>
      {loadError ? (
        <InlineNotice
          actionLabel={__('Retry', TEXT_DOMAIN)}
          onAction={() => {
            setLoadError('');
            setAttempt((value) => value + 1);
          }}
          tone="error"
        >
          {loadError}
        </InlineNotice>
      ) : (
        <div className="dj-pages" key={attempt}>
          {Array.from({ length: total }, (_, index) => index + 1).map((page) => (
            <PdfPageThumb
              key={page}
              onToggle={() => setPages((current) => current.includes(page) ? current.filter((item) => item !== page) : [...current, page].sort((a, b) => a - b))}
              open={stableOpen}
              page={page}
              selected={pages.includes(page)}
            />
          ))}
        </div>
      )}
      {changed ? (
        <button
          className="dj-button dj-button--small"
          disabled={busy || pages.length === 0}
          onClick={() => void imports.updateOptions(file.fileId, { pdf: { pages, renderMode } })}
          type="button"
        >
          {sprintf(_n('Use %d page', 'Use %d pages', pages.length, TEXT_DOMAIN), pages.length)}
        </button>
      ) : null}
      <label htmlFor={modeId}>{__('Page handling', TEXT_DOMAIN)}</label>
      <span className="dj-select">
        <select
          disabled={busy}
          id={modeId}
          onChange={(event) => void imports.updateOptions(file.fileId, { pdf: { pages: currentPages, renderMode: event.currentTarget.value as PdfRenderMode } })}
          value={renderMode}
        >
          <option value="auto">{__('Text, with figures as images', TEXT_DOMAIN)}</option>
          <option value="text">{__('Text only', TEXT_DOMAIN)}</option>
          <option value="image">{__('Every page as an image', TEXT_DOMAIN)}</option>
        </select>
      </span>
    </div>
  );
};

/* ------------------------------------------------------------------ */
/* Screen                                                              */
/* ------------------------------------------------------------------ */

type Props = {
  activeFile: ImportFile | null;
  imports: ImportSessionState;
  reviewed: string[];
  onSelectFile: (fileId: string) => void;
  onOpenDeck: (fileId: string) => void;
  onCancel: () => void;
  onClose: () => void;
};

const PreviewCanvas = ({ file, preview, imports }: { file: ImportFile; preview: ImportPreview | null; imports: ImportSessionState }): JSX.Element => {
  const error = imports.previewErrors[file.fileId];

  if (!fileCanPreview(file)) {
    const progress = serverFileProgress(file, imports.renders[file.fileId]);

    return (
      <div className="dj-canvas__inner">
        {file.status === 'failed'
          ? <InlineNotice tone="error">{file.error?.message || __('This file could not be converted.', TEXT_DOMAIN)}</InlineNotice>
          : <div className="dj-state"><span aria-hidden="true" className="dj-spinner" />&nbsp;{progress.label}</div>}
      </div>
    );
  }

  if (error) {
    return (
      <div className="dj-canvas__inner">
        <InlineNotice actionLabel={__('Retry', TEXT_DOMAIN)} onAction={() => void imports.loadPreview(file.fileId, true)} tone="error">{error}</InlineNotice>
      </div>
    );
  }

  if (!preview) {
    return <div className="dj-state"><span aria-hidden="true" className="dj-spinner" />&nbsp;{__('Loading preview…', TEXT_DOMAIN)}</div>;
  }

  const stale = preview.previewFingerprint !== file.previewFingerprint;
  const flags = mapWarningsToBlocks(preview.document, preview.warnings);
  const assets = { sessionId: imports.session?.sessionId ?? '', fileId: file.fileId, document: preview.document };

  return (
    <div aria-busy={stale} className="dj-canvas__inner">
      <div className="dj-canvas__meta">
        <span>{sprintf(__('Draft preview · %s', TEXT_DOMAIN), presetLabel(preview.layoutPreset))}</span>
        <span>
          {[sourceSummary(file), sprintf(_n('%s word', '%s words', preview.statistics.words, TEXT_DOMAIN), formatCount(preview.statistics.words))].filter(Boolean).join(' · ')}
        </span>
      </div>
      {stale ? <InlineNotice tone="info">{__('Updating the preview with your latest changes…', TEXT_DOMAIN)}</InlineNotice> : null}
      <article className="dj-doc" style={stale ? { opacity: 0.6 } : undefined}>
        <h1>{file.options.title || preview.title}</h1>
        {preview.document.sections.map((section) => (
          <SectionView assets={assets} flags={flags} key={section.id} section={section} />
        ))}
      </article>
    </div>
  );
};

const ChecksAside = ({ file, preview, imports, onOpenDeck }: {
  file: ImportFile;
  preview: ImportPreview | null;
  imports: ImportSessionState;
  onOpenDeck: (fileId: string) => void;
}): JSX.Element => {
  const titleId = useStableId('dj-title');
  const syncId = useStableId('dj-file-sync');
  const [title, setTitle] = useState(file.options.title);
  const warnings = preview?.warnings ?? file.warnings;
  const statistics = preview?.statistics ?? file.statistics;
  const busy = Boolean(imports.pendingOptions[file.fileId]) || imports.committing;
  const renderMode = file.options.pdf?.renderMode ?? 'auto';

  useEffect(() => {
    setTitle(file.options.title);
  }, [file.fileId, file.options.title]);

  const saveTitle = () => {
    const next = title.trim();

    if (next && next !== file.options.title) {
      void imports.updateOptions(file.fileId, { title: next });
    } else {
      setTitle(file.options.title);
    }
  };

  return (
    <aside aria-label={__('Check before creating', TEXT_DOMAIN)} className="dj-aside">
      <div>
        <h3 className="dj-eyebrow">{__('Check before creating', TEXT_DOMAIN)}</h3>
        {warnings.length === 0 ? (
          <p className="dj-match-meta" style={{ marginTop: 8 }}>{__('Nothing to check. Everything converted as it is in the file.', TEXT_DOMAIN)}</p>
        ) : (
          <div className="dj-checks">
            {warnings.map((warning) => (
              <div className={`dj-check${warning.severity === 'info' ? ' dj-check--info' : ''}`} key={warning.number}>
                <WarningNumber number={warning.number} />
                <div>
                  <strong>{warningTitle(warning)}</strong>
                  <p>{warning.message}</p>
                  {file.format === 'pdf' && renderMode !== 'image' && (warning.code === 'tableSimplified' || warning.code === 'pdfTableAsText') ? (
                    <button
                      className="dj-link"
                      disabled={busy}
                      onClick={() => void imports.updateOptions(file.fileId, { pdf: { pages: file.options.pdf?.pages ?? [], renderMode: 'image' } })}
                      title={__('Renders the selected pages as images instead of text.', TEXT_DOMAIN)}
                      type="button"
                    >
                      {__('Keep as image instead', TEXT_DOMAIN)}
                    </button>
                  ) : null}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {statistics ? (
        <dl className="dj-stats">
          <dt className="dj-eyebrow">{__('What came through', TEXT_DOMAIN)}</dt>
          <dd className="screen-reader-text" />
          <dt>{__('Headings detected', TEXT_DOMAIN)}</dt>
          <dd>{formatCount(statistics.headings)}</dd>
          <dt>{__('Images → Media Library', TEXT_DOMAIN)}</dt>
          <dd>{formatCount(statistics.images)}</dd>
          <dt>{__('Tables', TEXT_DOMAIN)}</dt>
          <dd>{formatCount(statistics.tables)}</dd>
          <dt>{__('Lists', TEXT_DOMAIN)}</dt>
          <dd>{formatCount(statistics.lists)}</dd>
          {file.format === 'pdf' ? (
            <>
              <dt>{__('Pages left out', TEXT_DOMAIN)}</dt>
              <dd>{formatCount(statistics.skippedPages)}</dd>
            </>
          ) : null}
          {file.format === 'pptx' ? (
            <>
              <dt>{__('Slides left out', TEXT_DOMAIN)}</dt>
              <dd>{formatCount(statistics.skippedSlides)}</dd>
            </>
          ) : null}
        </dl>
      ) : null}

      <div className="dj-aside__field">
        <label htmlFor={titleId}>{__('Post title', TEXT_DOMAIN)}</label>
        <input
          className="dj-input"
          disabled={busy}
          id={titleId}
          onBlur={saveTitle}
          onChange={(event) => setTitle(event.currentTarget.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              saveTitle();
            }
          }}
          type="text"
          value={title}
        />
      </div>

      {file.format === 'docx' ? (
        <div className="dj-option" style={{ borderBottom: 0 }}>
          <Switch
            checked={file.options.docx?.keepSynced !== false}
            describedBy={`${syncId}-desc`}
            disabled={busy}
            labelledBy={`${syncId}-label`}
            onChange={(checked) => void imports.updateOptions(file.fileId, { docx: { keepSynced: checked } })}
          />
          <div>
            <strong id={`${syncId}-label`}>{__('Keep it synced', TEXT_DOMAIN)}</strong>
            <p id={`${syncId}-desc`}>{__('Keeps the Google Doc copy linked, so edits there update the post.', TEXT_DOMAIN)}</p>
          </div>
        </div>
      ) : null}

      {file.format === 'pptx' ? (
        <button className="dj-button dj-button--small" onClick={() => onOpenDeck(file.fileId)} type="button">
          {__('Review slides and deck options', TEXT_DOMAIN)}
        </button>
      ) : null}

      {file.format === 'pdf' ? <PdfOptionsPanel file={file} imports={imports} /> : null}

      {imports.optionErrors[file.fileId] ? <InlineNotice tone="error">{imports.optionErrors[file.fileId]}</InlineNotice> : null}

      {file.format === 'docx' && file.options.docx?.keepSynced !== false ? (
        <InlineNotice tone="info">
          {sprintf(
            /* translators: %s: Google Drive folder name. */
            __('A Google Doc copy stays in My Drive / %s and stays linked. Edits there update the post on its next sync.', TEXT_DOMAIN),
            imports.session?.googleWrite.importFolderName ?? 'Imported from WordPress'
          )}
        </InlineNotice>
      ) : (
        <InlineNotice tone="warning">
          {file.format === 'pdf'
            ? __('PDFs are imported once. Later changes to the file won\'t update the post.', TEXT_DOMAIN)
            : file.format === 'pptx'
              ? __('Presentations are imported once. Later changes to the deck won\'t update the post.', TEXT_DOMAIN)
              : __('This Word file is imported once. Later changes to it won\'t update the post.', TEXT_DOMAIN)}
        </InlineNotice>
      )}
    </aside>
  );
};

const ResultsCanvas = ({ imports, onClose }: { imports: ImportSessionState; onClose: () => void }): JSX.Element => {
  const result = imports.session?.result;
  const files = imports.session?.files ?? [];
  const failed = result?.files.filter((item) => item.status === 'failed') ?? [];
  const created = result?.files.filter((item) => item.status === 'created') ?? [];

  return (
    <div className="dj-canvas__inner">
      <h2 className="dj-title" style={{ marginBottom: 6 }}>
        {sprintf(_n('%d draft created', '%d drafts created', created.length, TEXT_DOMAIN), created.length)}
      </h2>
      <p className="dj-subtitle" style={{ marginBottom: 18 }}>
        {failed.length > 0
          ? __('The files that failed are listed with the reason. Nothing else changed.', TEXT_DOMAIN)
          : __('Every draft is unpublished. Review and publish them when ready.', TEXT_DOMAIN)}
      </p>
      <div className="dj-results">
        {[...failed, ...created].map((item) => {
          const file = files.find((candidate) => candidate.fileId === item.fileId);

          return (
            <div className={`dj-result${item.status === 'failed' ? ' dj-result--failed' : ''}`} key={item.fileId}>
              {file ? <FormatBadge format={file.format} small /> : <span />}
              <div style={{ minWidth: 0 }}>
                <div className="dj-file__name">{file ? file.options.title || titleWithoutExtension(file.originalName) : item.fileId}</div>
                <div className="dj-file__meta">
                  {item.status === 'failed'
                    ? item.error?.message || __('Could not create this draft.', TEXT_DOMAIN)
                    : item.provenance === 'syncedWord'
                      ? __('Draft · linked to its Google Doc copy', TEXT_DOMAIN)
                      : __('Draft · imported once', TEXT_DOMAIN)}
                </div>
              </div>
              {item.editUrl ? <a className="dj-button dj-button--small" href={item.editUrl}>{__('Edit draft', TEXT_DOMAIN)}</a> : null}
            </div>
          );
        })}
      </div>
      <div style={{ marginTop: 18 }}>
        <button className="dj-button dj-button--primary dj-button--compact" onClick={onClose} type="button">{__('Done', TEXT_DOMAIN)}</button>
      </div>
    </div>
  );
};

export const ImportPreviewScreen = ({ activeFile, imports, reviewed, onSelectFile, onOpenDeck, onCancel, onClose }: Props): JSX.Element => {
  const files = imports.includedFiles;
  const committed = imports.session?.status === 'committed' && imports.session.result;
  const results = imports.session?.result?.files ?? [];
  const preview = activeFile ? imports.previews[activeFile.fileId]?.preview ?? null : null;
  const config = getAdminConfig();

  /* Load the active preview first, then every other ready file, so the commit only sends
     fingerprints whose previews were actually rendered here. */
  useEffect(() => {
    if (activeFile) {
      void imports.loadPreview(activeFile.fileId);
    }

    files.filter((file) => file.fileId !== activeFile?.fileId && fileCanPreview(file)).forEach((file) => {
      void imports.loadPreview(file.fileId);
    });
  }, [activeFile?.fileId, activeFile?.previewFingerprint, files, imports.loadPreview]);

  const stalePreviews = imports.committable.filter((file) => !imports.previewIsCurrent(file));
  const commitDisabled = !imports.canCommit || stalePreviews.length > 0;
  const syncedCount = imports.committable.filter((file) => file.format === 'docx' && file.options.docx?.keepSynced !== false).length;
  const oneTimeCount = imports.committable.length - syncedCount;
  const sharedPostType = files[0]?.options.target.postType ?? 'post';
  const sharedPreset = files[0]?.options.layoutPreset ?? '';
  const committingDone = (imports.session?.files ?? []).filter((file) => file.status === 'committed' || file.status === 'failed').length;
  const presetOptions = [
    { value: '', label: sprintf(__('%s layout', TEXT_DOMAIN), presetLabel('')) },
    ...config.availableLayoutPresets
      .filter((preset) => preset.id !== config.defaultLayoutPreset)
      .map((preset) => ({ value: preset.id, label: sprintf(__('%s layout', TEXT_DOMAIN), preset.label) }))
  ];

  return (
    <>
      <div className="dj-preview">
        <nav aria-label={__('Files', TEXT_DOMAIN)} className="dj-nav">
          <h3 className="dj-eyebrow">{sprintf(_n('%d file', '%d files', files.length, TEXT_DOMAIN), files.length)}</h3>
          {files.map((file) => {
            const meta = navMeta(file, imports, results.find((item) => item.fileId === file.fileId));

            return (
              <button
                aria-current={activeFile?.fileId === file.fileId}
                className="dj-nav__file"
                key={file.fileId}
                onClick={() => onSelectFile(file.fileId)}
                type="button"
              >
                <FormatBadge format={file.format} small />
                <span style={{ minWidth: 0 }}>
                  <span className="dj-nav__name">{file.options.title || titleWithoutExtension(file.originalName)}</span>
                  <span className={`dj-nav__meta${meta.tone ? ` dj-nav__meta--${meta.tone}` : ''}`}>
                    {file.format === 'pptx' && reviewed.includes(file.fileId) && !committed ? `${meta.text} · ${__('reviewed', TEXT_DOMAIN)}` : meta.text}
                  </span>
                </span>
              </button>
            );
          })}
        </nav>

        <div className="dj-canvas">
          {committed ? <ResultsCanvas imports={imports} onClose={onClose} /> : activeFile ? (
            <PreviewCanvas file={activeFile} imports={imports} preview={preview} />
          ) : (
            <div className="dj-state">{__('Upload a file to preview it here.', TEXT_DOMAIN)}</div>
          )}
        </div>

        {activeFile && !committed ? (
          <ChecksAside file={activeFile} imports={imports} onOpenDeck={onOpenDeck} preview={preview} />
        ) : <aside className="dj-aside" />}
      </div>

      <div className="dj-footer">
        {committed ? (
          <span className="dj-footer__status dj-footer__status--start">{__('Drafts are listed in Sources with their import source.', TEXT_DOMAIN)}</span>
        ) : (
          <>
            <NativeSelect
              disabled={imports.committing || imports.optionsPending || files.length === 0}
              label={__('Create as', TEXT_DOMAIN)}
              onChange={(value) => void imports.updateAllOptions({ target: { postType: value } })}
              options={postTypeDraftOptions()}
              value={sharedPostType}
            />
            <NativeSelect
              disabled={imports.committing || imports.optionsPending || files.length === 0}
              label={__('Layout', TEXT_DOMAIN)}
              onChange={(value) => void imports.updateAllOptions({ layoutPreset: value })}
              options={presetOptions}
              value={sharedPreset}
            />
            <span aria-live="polite" className="dj-footer__status">
              {imports.committing
                ? sprintf(
                  /* translators: 1: finished files, 2: files being created. */
                  __('Creating drafts · %1$d of %2$d', TEXT_DOMAIN),
                  committingDone,
                  imports.committable.length || (imports.session?.files.length ?? 0)
                )
                : imports.commitError
                  ? imports.commitError
                  : [
                      syncedCount > 0 ? sprintf(_n('%d synced', '%d synced', syncedCount, TEXT_DOMAIN), syncedCount) : '',
                      oneTimeCount > 0 ? sprintf(_n('%d one-time import', '%d one-time imports', oneTimeCount, TEXT_DOMAIN), oneTimeCount) : '',
                    imports.blockingFiles.length > 0 ? sprintf(_n('%d still processing', '%d still processing', imports.blockingFiles.length, TEXT_DOMAIN), imports.blockingFiles.length) : ''
                  ].filter(Boolean).join(' · ')}
            </span>
            <button className="dj-button dj-button--compact" disabled={imports.committing} onClick={onCancel} type="button">{__('Cancel', TEXT_DOMAIN)}</button>
            <button
              className="dj-button dj-button--primary dj-button--compact"
              disabled={commitDisabled}
              onClick={() => void imports.commit()}
              type="button"
            >
              {imports.committing
                ? __('Creating…', TEXT_DOMAIN)
                : sprintf(_n('Create %d draft', 'Create %d drafts', Math.max(imports.committable.length, 1), TEXT_DOMAIN), imports.committable.length)}
            </button>
          </>
        )}
      </div>
    </>
  );
};
