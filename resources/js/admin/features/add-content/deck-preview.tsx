/**
 * Deck review for one PPTX file: every slide with its real Slides thumbnail and warning
 * numbers, per-slide include/skip, the section a slide becomes, and the deck options.
 * PPTX options never reconvert; the server recomputes the fingerprint and the preview
 * reloads, and "Looks good" stays disabled until it does.
 */
import { createElement, Fragment, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { importAssetUrl } from '../../api/journey-api';
import type { Block, ImportFile, ImportPreview, PptxOptions, Section } from '../../api/journey-types';
import { InlineNotice, Switch, WarningNumber } from './add-content-dialog';
import { mapWarningsToBlocks, presetLabel, SectionView } from './import-preview';
import { type ImportSessionState, titleWithoutExtension, useStableId } from './use-add-content';

const TEXT_DOMAIN = 'brasth-document-sync-for-google-docs';
const WORDS_PER_MINUTE = 230;

/** Header line: "18 slides → 14 sections · 1 post · about 6 min read". */
export const deckHeaderSummary = (file: ImportFile, preview: ImportPreview | null): string => {
  const slides = file.sourceCount.slides ?? file.slideThumbnails.length;
  const statistics = preview?.statistics ?? file.statistics;

  if (!statistics) {
    return sprintf(_n('%d slide', '%d slides', slides, TEXT_DOMAIN), slides);
  }

  const minutes = Math.max(1, Math.round(statistics.words / WORDS_PER_MINUTE));

  return [
    sprintf(
      /* translators: 1: slide count, 2: section count. */
      __('%1$d slides → %2$d sections', TEXT_DOMAIN),
      slides,
      statistics.sections
    ),
    __('1 post', TEXT_DOMAIN),
    sprintf(_n('about %d min read', 'about %d min read', minutes, TEXT_DOMAIN), minutes)
  ].join(' · ');
};

const slideSections = (preview: ImportPreview | null, slide: number): { main: Section | null; notes: Section | null } => {
  const sections = preview?.document.sections ?? [];

  return {
    main: sections.find((section) => section.kind === 'slide' && section.origin.slide === slide) ?? null,
    notes: sections.find((section) => section.kind === 'notes' && section.origin.slide === slide) ?? null
  };
};

const listItemCount = (block: Extract<Block, { type: 'list' }>): number => block.items.length;

/** Short description of what a slide contains, from its effective section. */
const describeSection = (main: Section | null, notes: Section | null): string => {
  if (!main) {
    return '';
  }

  const parts: string[] = [];
  const headings = main.blocks.filter((block) => block.type === 'heading').length;
  const lists = main.blocks.filter((block): block is Extract<Block, { type: 'list' }> => block.type === 'list');
  const bullets = lists.reduce((total, block) => total + listItemCount(block), 0);
  const images = main.blocks.filter((block) => block.type === 'image').length;
  const tables = main.blocks.filter((block): block is Extract<Block, { type: 'table' }> => block.type === 'table');
  const paragraphs = main.blocks.filter((block) => block.type === 'paragraph').length;

  if (headings > 0) {
    parts.push(__('Heading', TEXT_DOMAIN));
  }

  if (bullets > 0) {
    parts.push(sprintf(_n('%d bullet', '%d bullets', bullets, TEXT_DOMAIN), bullets));
  }

  if (tables.length > 0) {
    const first = tables[0];
    parts.push(sprintf(
      /* translators: 1: columns, 2: rows. */
      __('Table · %1$d × %2$d', TEXT_DOMAIN),
      first.rows[0]?.cells.length ?? 0,
      first.rows.length
    ));
  }

  if (images > 0) {
    parts.push(_n('image', 'images', images, TEXT_DOMAIN));
  }

  if (paragraphs > 0 && bullets === 0 && tables.length === 0) {
    parts.push(__('text', TEXT_DOMAIN));
  }

  if (notes) {
    parts.push(__('notes', TEXT_DOMAIN));
  }

  return parts.length === 2 && headings > 0 ? parts.join(' + ') : parts.join(', ');
};

type MappingRow = { from: string; to: string };

const mappingRows = (main: Section, notes: Section | null): MappingRow[] => {
  const rows: MappingRow[] = main.blocks.map((block): MappingRow => {
    switch (block.type) {
      case 'heading':
        return { from: __('Slide title', TEXT_DOMAIN), to: sprintf(__('H%d heading', TEXT_DOMAIN), block.level) };
      case 'list':
        return { from: sprintf(_n('%d bullet', '%d bullets', listItemCount(block), TEXT_DOMAIN), listItemCount(block)), to: __('List block', TEXT_DOMAIN) };
      case 'table':
        return {
          from: sprintf(__('Table %1$d × %2$d', TEXT_DOMAIN), block.rows[0]?.cells.length ?? 0, block.rows.length),
          to: __('Table block', TEXT_DOMAIN)
        };
      case 'image':
        return block.fallback
          ? {
            from: __('Unsupported visual', TEXT_DOMAIN),
            to: sprintf(
              /* translators: %s: warning numbers. */
              __('Slide image (check %s)', TEXT_DOMAIN),
              block.fallback.warningNumbers.join(', ')
            )
          }
          : { from: __('Image', TEXT_DOMAIN), to: __('Image block, full width', TEXT_DOMAIN) };
      default:
        return { from: __('Text', TEXT_DOMAIN), to: __('Paragraph', TEXT_DOMAIN) };
    }
  });

  if (notes) {
    rows.push({ from: __('Speaker notes', TEXT_DOMAIN), to: __('Paragraph below the section', TEXT_DOMAIN) });
  }

  return rows;
};

const normalizeTitle = (value: string | null): string => (value ?? '').trim().toLowerCase().replace(/\s+/g, ' ');

/** Consecutive included slides that share a title, e.g. slides 7–9 "Roadmap". */
const sameTitleRuns = (file: ImportFile, included: number[]): { from: number; to: number; title: string }[] => {
  const runs: { from: number; to: number; title: string }[] = [];
  const ordered = file.slideThumbnails.filter((thumb) => included.includes(thumb.slide)).sort((a, b) => a.slide - b.slide);

  ordered.forEach((thumb, index) => {
    const previous = ordered[index - 1];

    if (previous && thumb.title && normalizeTitle(thumb.title) === normalizeTitle(previous.title)) {
      const last = runs[runs.length - 1];

      if (last && last.to === previous.slide) {
        last.to = thumb.slide;
      } else {
        runs.push({ from: previous.slide, to: thumb.slide, title: thumb.title });
      }
    }
  });

  return runs;
};

type Props = {
  file: ImportFile;
  imports: ImportSessionState;
  onBack: () => void;
  onLooksGood: () => void;
};

export const DeckPreview = ({ file, imports, onBack, onLooksGood }: Props): JSX.Element => {
  const options: PptxOptions = file.options.pptx ?? { slides: [], includeNotes: false, addSlideImages: false, mergeConsecutiveTitles: false };
  const included = options.slides;
  const preview = imports.previews[file.fileId]?.preview ?? null;
  const previewCurrent = imports.previewIsCurrent(file);
  const busy = Boolean(imports.pendingOptions[file.fileId]) || imports.committing;
  const suggested = file.detection?.suggestedSkips ?? [];
  const thumbs = [...file.slideThumbnails].sort((a, b) => a.slide - b.slide);
  const [current, setCurrent] = useState<number>(() => included[0] ?? thumbs[0]?.slide ?? 1);
  const [editingTitle, setEditingTitle] = useState(false);
  const [titleDraft, setTitleDraft] = useState(file.options.title);
  const ids = {
    skip: useStableId('dj-deck-skip'),
    notes: useStableId('dj-deck-notes'),
    images: useStableId('dj-deck-images'),
    merge: useStableId('dj-deck-merge'),
    title: useStableId('dj-deck-title')
  };

  useEffect(() => {
    void imports.loadPreview(file.fileId);
  }, [file.fileId, file.previewFingerprint, imports.loadPreview]);

  useEffect(() => {
    setTitleDraft(file.options.title);
  }, [file.options.title]);

  const flags = useMemo(() => preview ? mapWarningsToBlocks(preview.document, preview.warnings) : {}, [preview]);
  const savePptx = (patch: Partial<PptxOptions>) => {
    void imports.updateOptions(file.fileId, { pptx: { ...options, ...patch } });
  };

  const toggleSlide = (slide: number) => {
    const next = included.includes(slide)
      ? included.filter((value) => value !== slide)
      : [...included, slide].sort((a, b) => a - b);

    if (next.length > 0) {
      savePptx({ slides: next });
    }
  };

  const skipSuggested = suggested.length > 0 && suggested.every((skip) => !included.includes(skip.slide));
  const toggleSkipSuggested = (checked: boolean) => {
    const suggestedSlides = suggested.map((skip) => skip.slide);
    const next = checked
      ? included.filter((slide) => !suggestedSlides.includes(slide))
      : Array.from(new Set([...included, ...suggestedSlides])).sort((a, b) => a - b);

    if (next.length > 0) {
      savePptx({ slides: next });
    }
  };

  const skipDescription = (() => {
    if (suggested.length === 0) {
      return __('No cover or closing slide was detected.', TEXT_DOMAIN);
    }

    const titleOnly = suggested.find((skip) => skip.reason === 'titleOnly');
    const closing = suggested.find((skip) => skip.reason === 'closing');

    if (titleOnly && closing) {
      return sprintf(
        /* translators: 1: cover slide number, 2: closing slide number. */
        __('Slides %1$d and %2$d look like a cover and a thank-you.', TEXT_DOMAIN),
        titleOnly.slide,
        closing.slide
      );
    }

    return titleOnly
      ? sprintf(__('Slide %d looks like a cover.', TEXT_DOMAIN), titleOnly.slide)
      : sprintf(__('Slide %d looks like a thank-you.', TEXT_DOMAIN), closing?.slide ?? 0);
  })();

  const notesFound = options.includeNotes && preview
    ? preview.document.sections.filter((section) => section.kind === 'notes').length
    : null;
  const mergeRuns = sameTitleRuns(file, included);
  const firstSlideTitle = thumbs[0]?.title ?? null;
  const titleFromSlide = Boolean(firstSlideTitle && normalizeTitle(firstSlideTitle) === normalizeTitle(file.options.title));
  const { main, notes } = slideSections(preview, current);
  const currentThumb = thumbs.find((thumb) => thumb.slide === current) ?? null;
  const currentSkip = suggested.find((skip) => skip.slide === current);
  const mergedInto = !main && included.includes(current) && options.mergeConsecutiveTitles
    ? mergeRuns.find((run) => current > run.from && current <= run.to)?.from ?? null
    : null;
  const mapping = main ? mappingRows(main, notes) : [];
  const assets = preview ? { sessionId: imports.session?.sessionId ?? '', fileId: file.fileId, document: preview.document } : null;

  const saveTitle = () => {
    const next = titleDraft.trim() || titleWithoutExtension(file.originalName);

    setEditingTitle(false);

    if (next !== file.options.title) {
      void imports.updateOptions(file.fileId, { title: next });
    }
  };

  return (
    <>
      <div className="dj-deck">
        <div aria-label={__('Slides', TEXT_DOMAIN)} className="dj-slides" role="list">
          <div className="dj-slides__head">
            <h3 className="dj-eyebrow">{__('Slides', TEXT_DOMAIN)}</h3>
            <span className="dj-slides__count">
              {sprintf(
                /* translators: 1: included slides, 2: total slides. */
                __('%1$d of %2$d included', TEXT_DOMAIN),
                included.length,
                thumbs.length
              )}
            </span>
          </div>
          {thumbs.map((thumb) => {
            const isIncluded = included.includes(thumb.slide);
            const skip = suggested.find((item) => item.slide === thumb.slide);
            const sections = slideSections(preview, thumb.slide);
            const description = isIncluded
              ? describeSection(sections.main, sections.notes) || (options.mergeConsecutiveTitles && !sections.main ? __('Merged with the slide before', TEXT_DOMAIN) : '')
              : skip?.reason === 'titleOnly'
                ? __('Title slide · skipped', TEXT_DOMAIN)
                : skip?.reason === 'closing'
                  ? __('Closing slide · skipped', TEXT_DOMAIN)
                  : __('Skipped', TEXT_DOMAIN);
            const title = thumb.title || sprintf(__('Slide %d', TEXT_DOMAIN), thumb.slide);

            return (
              <div
                aria-current={current === thumb.slide}
                className={`dj-slide${isIncluded ? '' : ' dj-slide--skipped'}`}
                key={thumb.slide}
                onClick={(event) => {
                  if (!(event.target as HTMLElement).closest('input')) {
                    setCurrent(thumb.slide);
                  }
                }}
                role="listitem"
              >
                <span className="dj-checkbox-hit">
                  <input
                    aria-label={sprintf(__('Include slide %1$d: %2$s', TEXT_DOMAIN), thumb.slide, title)}
                    checked={isIncluded}
                    className="dj-checkbox"
                    disabled={busy || (isIncluded && included.length === 1)}
                    onChange={() => toggleSlide(thumb.slide)}
                    type="checkbox"
                  />
                </span>
                <span className="dj-slide__number">{thumb.slide}</span>
                <span className="dj-slide__thumb">
                  <img alt="" loading="lazy" src={importAssetUrl(imports.session?.sessionId ?? '', file.fileId, thumb.assetId)} />
                  {thumb.warningNumbers.length > 0 ? (
                    <span className="dj-slide__warnings">
                      {thumb.warningNumbers.map((number) => <WarningNumber key={number} number={number} />)}
                    </span>
                  ) : null}
                </span>
                <button
                  className="dj-slide__copy dj-row__title-button"
                  onClick={() => setCurrent(thumb.slide)}
                  style={{ whiteSpace: 'normal' }}
                  type="button"
                >
                  <span className="dj-slide__title">{title}</span>
                  <span className="dj-slide__desc">{description}</span>
                </button>
              </div>
            );
          })}
        </div>

        <div className="dj-deck__canvas">
          <div className="dj-deck__canvas-inner">
            <div className="dj-canvas__meta">
              <span>
                {included.includes(current)
                  ? sprintf(__('Slide %d becomes this section', TEXT_DOMAIN), current)
                  : sprintf(__('Slide %d is skipped', TEXT_DOMAIN), current)}
              </span>
              <span>{presetLabel(preview?.layoutPreset ?? file.options.layoutPreset)}</span>
            </div>

            {imports.previewErrors[file.fileId] ? (
              <InlineNotice actionLabel={__('Retry', TEXT_DOMAIN)} onAction={() => void imports.loadPreview(file.fileId, true)} tone="error">
                {imports.previewErrors[file.fileId]}
              </InlineNotice>
            ) : null}

            {!preview ? (
              <div className="dj-state"><span aria-hidden="true" className="dj-spinner" />&nbsp;{__('Loading preview…', TEXT_DOMAIN)}</div>
            ) : !included.includes(current) ? (
              <div className="dj-doc">
                {currentThumb ? <img alt="" src={importAssetUrl(imports.session?.sessionId ?? '', file.fileId, currentThumb.assetId)} /> : null}
                <p>
                  {currentSkip
                    ? __('Document Sync suggests leaving this slide out. Tick it to add it to the post.', TEXT_DOMAIN)
                    : __('This slide is left out of the post. Tick it to add it back.', TEXT_DOMAIN)}
                </p>
              </div>
            ) : mergedInto !== null ? (
              <div className="dj-doc">
                <p>{sprintf(__('This slide continues slide %d and is merged into its section.', TEXT_DOMAIN), mergedInto)}</p>
              </div>
            ) : main && assets ? (
              <>
                {mapping.length > 0 ? (
                  <div className="dj-mapping" style={{ ['--dj-mapping-rows' as string]: String(mapping.length) }}>
                    {mapping.map((row, index) => (
                      <Fragment key={`${row.from}-${index}`}>
                        <span className="dj-mapping__from" style={{ gridColumn: 1, gridRow: index + 1 }}>{row.from}</span>
                        <span className="dj-mapping__to" style={{ gridRow: index + 1 }}>{row.to}</span>
                      </Fragment>
                    ))}
                    <span aria-hidden="true" className="dj-mapping__arrow">→</span>
                  </div>
                ) : null}
                <article aria-busy={!previewCurrent} className="dj-doc" style={previewCurrent ? undefined : { opacity: 0.6 }}>
                  <SectionView assets={assets} flags={flags} section={main} />
                  {notes ? <SectionView assets={assets} flags={flags} section={notes} /> : null}
                </article>
              </>
            ) : (
              <div className="dj-state">{__('This slide has no content to import.', TEXT_DOMAIN)}</div>
            )}
          </div>
        </div>

        <div className="dj-options">
          <h3 className="dj-eyebrow" style={{ marginBottom: 4 }}>{__('Deck options', TEXT_DOMAIN)}</h3>
          <div className="dj-option">
            <Switch checked={skipSuggested} describedBy={`${ids.skip}-d`} disabled={busy || suggested.length === 0} labelledBy={ids.skip} onChange={toggleSkipSuggested} />
            <div>
              <strong id={ids.skip}>{__('Skip title and closing slides', TEXT_DOMAIN)}</strong>
              <p id={`${ids.skip}-d`}>{skipDescription}</p>
            </div>
          </div>
          <div className="dj-option">
            <Switch
              checked={options.includeNotes}
              describedBy={`${ids.notes}-d`}
              disabled={busy || !file.detection?.hasNotes}
              labelledBy={ids.notes}
              onChange={(checked) => savePptx({ includeNotes: checked })}
            />
            <div>
              <strong id={ids.notes}>{__('Include speaker notes', TEXT_DOMAIN)}</strong>
              <p id={`${ids.notes}-d`}>
                {!file.detection?.hasNotes
                  ? __('This deck has no speaker notes.', TEXT_DOMAIN)
                  : notesFound !== null
                    ? sprintf(_n('Added as a paragraph under each section. Found on %d slide.', 'Added as a paragraph under each section. Found on %d slides.', notesFound, TEXT_DOMAIN), notesFound)
                    : __('Added as a paragraph under each section.', TEXT_DOMAIN)}
              </p>
            </div>
          </div>
          <div className="dj-option">
            <Switch checked={options.addSlideImages} describedBy={`${ids.images}-d`} disabled={busy} labelledBy={ids.images} onChange={(checked) => savePptx({ addSlideImages: checked })} />
            <div>
              <strong id={ids.images}>{__('Also add each slide as an image', TEXT_DOMAIN)}</strong>
              <p id={`${ids.images}-d`}>{__('Keeps the original design next to the text. Larger post.', TEXT_DOMAIN)}</p>
            </div>
          </div>
          <div className="dj-option">
            <Switch checked={options.mergeConsecutiveTitles} describedBy={`${ids.merge}-d`} disabled={busy} labelledBy={ids.merge} onChange={(checked) => savePptx({ mergeConsecutiveTitles: checked })} />
            <div>
              <strong id={ids.merge}>{__('Merge slides with the same title', TEXT_DOMAIN)}</strong>
              <p id={`${ids.merge}-d`}>
                {mergeRuns.length === 0
                  ? __('No consecutive slides share a title.', TEXT_DOMAIN)
                  : mergeRuns.map((run) => sprintf(
                    /* translators: 1: first slide, 2: last slide, 3: shared title. */
                    __('Slides %1$d–%2$d continue “%3$s”.', TEXT_DOMAIN),
                    run.from,
                    run.to,
                    run.title
                  )).join(' ')}
              </p>
            </div>
          </div>

          {imports.optionErrors[file.fileId] ? <InlineNotice tone="error">{imports.optionErrors[file.fileId]}</InlineNotice> : null}

          <div className="dj-title-note">
            {editingTitle ? (
              <form
                className="dj-title-note__form"
                onSubmit={(event) => {
                  event.preventDefault();
                  saveTitle();
                }}
              >
                <label htmlFor={ids.title}>{__('Post title', TEXT_DOMAIN)}</label>
                <input
                  autoFocus
                  className="dj-input"
                  id={ids.title}
                  onChange={(event) => setTitleDraft(event.currentTarget.value)}
                  type="text"
                  value={titleDraft}
                />
                <span className="dj-title-note__actions">
                  <button className="dj-button dj-button--small dj-button--primary" disabled={busy} type="submit">{__('Save', TEXT_DOMAIN)}</button>
                  <button
                    className="dj-button dj-button--small"
                    onClick={() => {
                      setEditingTitle(false);
                      setTitleDraft(file.options.title);
                    }}
                    type="button"
                  >
                    {__('Cancel', TEXT_DOMAIN)}
                  </button>
                </span>
              </form>
            ) : (
              <>
                {titleFromSlide ? __('Slide 1 title becomes the post title: ', TEXT_DOMAIN) : __('Post title: ', TEXT_DOMAIN)}
                <strong>{file.options.title}</strong>
                {'. '}
                <button className="dj-link" disabled={busy} onClick={() => setEditingTitle(true)} type="button">{__('Change', TEXT_DOMAIN)}</button>
              </>
            )}
          </div>
        </div>
      </div>

      <div className="dj-footer">
        <span className="dj-footer__status dj-footer__status--start">
          {__('One-time import. Later changes to the deck won\'t update the post; import it again to replace.', TEXT_DOMAIN)}
        </span>
        <button className="dj-button dj-button--compact" onClick={onBack} type="button">{__('Back to all files', TEXT_DOMAIN)}</button>
        <button
          className="dj-button dj-button--primary dj-button--compact"
          disabled={busy || !previewCurrent || file.status !== 'ready'}
          onClick={onLooksGood}
          type="button"
        >
          {__('Looks good', TEXT_DOMAIN)}
        </button>
      </div>
    </>
  );
};
