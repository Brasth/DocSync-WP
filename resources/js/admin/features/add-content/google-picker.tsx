/**
 * Google Docs tab: paste-a-link check and Drive browsing side by side. Verified links
 * and browsed rows share one de-duplicated selection that survives chip, folder,
 * search, and page changes. Linked and unreadable Docs are shown but cannot be chosen.
 */
import * as Dialog from '@radix-ui/react-dialog';
import { createElement, Fragment } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { DocSourceTarget } from '../doc-source-modal/use-doc-source-modal';
import { getAdminConfig } from '../../config';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { Icon, InlineNotice, NativeSelect } from './add-content-dialog';
import {
  type DriveChip,
  type DriveRow,
  elementorChoiceAvailable,
  encodeLayoutChoice,
  formatEditedDate,
  type GoogleSelection,
  MAX_BATCH_DOCS,
  useStableId
} from './use-add-content';

const TEXT_DOMAIN = 'brasth-document-sync-for-google-docs';

const editPostUrl = (postId: number): string => `post.php?post=${encodeURIComponent(String(postId))}&action=edit`;

type Props = {
  google: GoogleSelection;
  target: DocSourceTarget;
};

const CHIPS: { id: DriveChip; label: string }[] = [
  { id: 'recent', label: __('Recent', TEXT_DOMAIN) },
  { id: 'myDrive', label: __('My Drive', TEXT_DOMAIN) },
  { id: 'sharedDrive', label: __('Shared drives', TEXT_DOMAIN) },
  { id: 'sharedWithMe', label: __('Shared with me', TEXT_DOMAIN) }
];

type LayoutSelectOption = { value: string; label: string };
type LayoutSelectGroup = { label: string; options: LayoutSelectOption[] };

export const layoutSelectGroups = (): LayoutSelectGroup[] => {
  const config = getAdminConfig();
  const defaultPreset = config.availableLayoutPresets.find((preset) => preset.id === config.defaultLayoutPreset);
  const gutenberg: LayoutSelectOption[] = [
    {
      value: encodeLayoutChoice({ kind: 'gutenberg', presetId: '' }),
      label: sprintf(
        /* translators: %s: layout preset name. */
        __('%s layout', TEXT_DOMAIN),
        defaultPreset?.label ?? __('Site default', TEXT_DOMAIN)
      )
    },
    ...config.availableLayoutPresets
      .filter((preset) => preset.id !== config.defaultLayoutPreset)
      .map((preset) => ({
        value: encodeLayoutChoice({ kind: 'gutenberg', presetId: preset.id }),
        label: sprintf(__('%s layout', TEXT_DOMAIN), preset.label)
      }))
  ];
  const groups: LayoutSelectGroup[] = [{ label: __('Block editor', TEXT_DOMAIN), options: gutenberg }];

  if (elementorChoiceAvailable()) {
    groups.push({
      label: __('Elementor', TEXT_DOMAIN),
      options: config.availableElementorLayoutPresets.map((preset) => ({
        value: encodeLayoutChoice({ kind: 'elementor', presetId: preset.id }),
        label: sprintf(
          /* translators: %s: Elementor layout preset name. */
          __('Elementor: %s', TEXT_DOMAIN),
          preset.label
        )
      }))
    });
  }

  return groups;
};

export const postTypeDraftOptions = () => {
  const config = getAdminConfig();

  return config.availablePostTypes
    .filter((postType) => config.enabledPostTypes.includes(postType.name))
    .map((postType) => ({
      value: postType.name,
      label: sprintf(
        /* translators: %s: post type label such as Post. */
        __('%s drafts', TEXT_DOMAIN),
        postType.label
      )
    }));
};

const DocRow = ({ row, google }: { row: Extract<DriveRow, { kind: 'document' }>; google: GoogleSelection }): JSX.Element => {
  const selected = google.isSelected(row.item.fileId);
  const disabled = row.blocked || row.linked;
  const failure = google.failures[row.item.fileId];
  const classes = [
    'dj-row',
    selected ? 'dj-row--selected' : '',
    disabled ? 'dj-row--disabled' : 'dj-row--selectable'
  ].filter(Boolean).join(' ');
  const checkboxLabel = sprintf(
    /* translators: %s: Google Doc name. */
    __('Select %s', TEXT_DOMAIN),
    row.item.name
  );

  return (
    <div
      className={classes}
      onClick={(event) => {
        if (disabled || (event.target as HTMLElement).closest('a, input, button')) {
          return;
        }

        google.toggleRow(row);
      }}
      role="row"
    >
      <span className="dj-checkbox-hit" role="cell">
        <input
          aria-label={checkboxLabel}
          checked={selected || row.linked}
          className="dj-checkbox"
          disabled={disabled}
          onChange={() => google.toggleRow(row)}
          type="checkbox"
        />
      </span>
      <span className="dj-row__icon" role="cell">
        <Icon name={row.blocked ? 'lock' : 'doc'} />
      </span>
      <span className="dj-row__name" role="cell">
        <span className="dj-row__title">{row.item.name}</span>
        {row.linked ? (
          <span className="dj-row__note">
            {' · '}
            {row.item.linkedPostId ? (
              <>
                {__('already linked to ', TEXT_DOMAIN)}
                <a href={editPostUrl(row.item.linkedPostId)}>{__('a post', TEXT_DOMAIN)}</a>
              </>
            ) : __('already linked to another post', TEXT_DOMAIN)}
          </span>
        ) : null}
        {!row.linked && row.blocked ? (
          <span className="dj-row__note">
            {' · '}
            {__('no download access — ', TEXT_DOMAIN)}
            <a href={row.item.webViewLink} rel="noopener noreferrer" target="_blank">{__('ask owner', TEXT_DOMAIN)}</a>
          </span>
        ) : null}
        {failure ? <span className="dj-row__error">{failure}</span> : null}
      </span>
      <span className="dj-row__cell dj-row__owner" role="cell">{row.ownerLabel}</span>
      <span className="dj-row__cell" role="cell">{formatEditedDate(row.item.modifiedTime)}</span>
      <span className="dj-row__spacer" role="cell" />
    </div>
  );
};

const FolderRow = ({ row, google }: { row: Extract<DriveRow, { kind: 'folder' }>; google: GoogleSelection }): JSX.Element => (
  <div className="dj-row dj-row--folder" onClick={() => google.openFolder(row)} role="row">
    <span className="dj-checkbox-hit" role="cell" />
    <span className="dj-row__icon dj-row__icon--folder" role="cell">
      <Icon name={row.id.startsWith('drive:') ? 'drive' : 'folder'} />
    </span>
    <span className="dj-row__name" role="cell">
      <button
        className="dj-row__title-button"
        onClick={(event) => {
          event.stopPropagation();
          google.openFolder(row);
        }}
        type="button"
      >
        {row.name}
      </button>
    </span>
    <span className="dj-row__cell dj-row__owner" role="cell">{row.ownerLabel}</span>
    <span className="dj-row__cell" role="cell">{row.modifiedTime ? formatEditedDate(row.modifiedTime) : ''}</span>
    <span className="dj-row__spacer" role="cell" />
  </div>
);

export const GooglePicker = ({ google, target }: Props): JSX.Element => {
  const inputId = useStableId('dj-google');
  const count = google.selected.length;
  const isNew = target.mode === 'new';
  const verifiedIds = new Set(google.verified.map((doc) => doc.fileId));
  const otherFailures = google.selected.filter((doc) => google.failures[doc.fileId] && !verifiedIds.has(doc.fileId) && !google.rows.some((row) => row.kind === 'document' && row.item.fileId === doc.fileId));
  const chipLabel = CHIPS.find((chip) => chip.id === google.chip)?.label ?? '';
  const primaryLabel = isNew
    ? count === 0
      ? __('Create drafts', TEXT_DOMAIN)
      : sprintf(
        /* translators: %d: number of drafts. */
        _n('Create %d draft', 'Create %d drafts', count, TEXT_DOMAIN),
        count
      )
    : __('Link Doc', TEXT_DOMAIN);

  return (
    <>
      <div className="dj-body">
        <div className="dj-url-block">
          <label className="dj-field-label" htmlFor={inputId} style={{ marginBottom: 0 }}>
            {__('Have the Doc open? Paste its link', TEXT_DOMAIN)}
          </label>
          <form
            className="dj-url-row"
            onSubmit={(event) => {
              event.preventDefault();
              void google.checkUrl();
            }}
          >
            <input
              aria-describedby={google.urlError ? `${inputId}-error` : undefined}
              aria-invalid={google.urlError ? true : undefined}
              autoComplete="off"
              className="dj-input"
              id={inputId}
              inputMode="url"
              onChange={(event) => google.setUrlInput(event.currentTarget.value)}
              placeholder="https://docs.google.com/document/d/…/edit"
              spellCheck={false}
              type="text"
              value={google.urlInput}
            />
            <button className="dj-button" disabled={google.checking || google.urlInput.trim() === ''} type="submit">
              {google.checking ? __('Checking…', TEXT_DOMAIN) : __('Check', TEXT_DOMAIN)}
            </button>
          </form>
          {google.urlError ? <p className="dj-field-error" id={`${inputId}-error`} role="alert">{google.urlError}</p> : null}
          {google.verified.map((doc) => {
            const failure = google.failures[doc.fileId];
            const edited = formatEditedDate(doc.modifiedTime);

            return (
              <div className={`dj-verified${failure ? ' dj-verified--failed' : ''}`} key={doc.fileId}>
                <span aria-hidden="true" className="dj-verified__icon"><Icon name="doc" /></span>
                <div style={{ minWidth: 0 }}>
                  <div className="dj-verified__name">{doc.name}</div>
                  <div className="dj-verified__meta">
                    {failure || [
                      doc.ownerLabel ? sprintf(__('Owned by %s', TEXT_DOMAIN), doc.ownerLabel) : '',
                      edited ? sprintf(__('edited %s', TEXT_DOMAIN), edited) : '',
                      __('you can read it ✓', TEXT_DOMAIN)
                    ].filter(Boolean).join(' · ')}
                  </div>
                </div>
                <button
                  aria-label={sprintf(__('Remove %s', TEXT_DOMAIN), doc.name)}
                  className="dj-icon-button"
                  onClick={() => google.removeVerified(doc.fileId)}
                  type="button"
                >
                  <span aria-hidden="true">&times;</span>
                </button>
              </div>
            );
          })}
        </div>

        <div className="dj-separator">{__('or pick from Drive', TEXT_DOMAIN)}</div>

        <div className="dj-browse">
          <div className="dj-browse__bar">
            <div aria-label={__('Drive location', TEXT_DOMAIN)} className="dj-chips" role="group">
              {CHIPS.map((chip) => (
                <button
                  aria-pressed={google.chip === chip.id && google.search.trim() === ''}
                  className="dj-chip"
                  key={chip.id}
                  onClick={() => google.changeChip(chip.id)}
                  type="button"
                >
                  {chip.label}
                </button>
              ))}
            </div>
            <div className="dj-search">
              <label className="screen-reader-text" htmlFor={`${inputId}-search`}>{__('Search all of Drive', TEXT_DOMAIN)}</label>
              <input
                className="dj-input"
                id={`${inputId}-search`}
                onChange={(event) => google.setSearch(event.currentTarget.value)}
                placeholder={__('Search all of Drive', TEXT_DOMAIN)}
                type="search"
                value={google.search}
              />
            </div>
          </div>

          {google.folderStack.length > 0 && google.search.trim() === '' ? (
            <nav aria-label={__('Folder path', TEXT_DOMAIN)} className="dj-crumbs">
              <button className="dj-link" onClick={() => google.changeChip(google.chip)} type="button">{chipLabel}</button>
              {google.folderStack.map((crumb, index) => (
                <Fragment key={`${crumb.folderId}-${index}`}>
                  <span aria-hidden="true">/</span>
                  {index === google.folderStack.length - 1 ? (
                    <span aria-current="page">{crumb.name}</span>
                  ) : (
                    <button className="dj-link" onClick={() => google.goToFolder(index)} type="button">{crumb.name}</button>
                  )}
                </Fragment>
              ))}
            </nav>
          ) : null}

          <div aria-busy={google.listing} aria-label={__('Google Drive files', TEXT_DOMAIN)} className="dj-table" role="table">
            <div className="dj-row dj-row--head" role="row">
              <span role="columnheader"><span className="screen-reader-text">{__('Select', TEXT_DOMAIN)}</span></span>
              <span className="dj-row__icon" role="columnheader" style={{ background: 'none' }} />
              <span role="columnheader">{__('Name', TEXT_DOMAIN)}</span>
              <span className="dj-row__owner" role="columnheader">{__('Owner', TEXT_DOMAIN)}</span>
              <span className="dj-row__edited-head" role="columnheader">{__('Edited', TEXT_DOMAIN)}</span>
              <span className="dj-row__spacer" role="columnheader" />
            </div>
            {google.rows.map((row) => row.kind === 'folder'
              ? <FolderRow google={google} key={row.id} row={row} />
              : <DocRow google={google} key={row.id} row={row} />)}
            {google.listing ? (
              <div className="dj-table__state" role="status"><span aria-hidden="true" className="dj-spinner" /> {__('Loading Drive…', TEXT_DOMAIN)}</div>
            ) : null}
            {!google.listing && google.listError ? (
              <div className="dj-table__state">
                <InlineNotice actionLabel={__('Retry', TEXT_DOMAIN)} onAction={() => void google.retryList()} tone="error">{google.listError}</InlineNotice>
              </div>
            ) : null}
            {!google.listing && !google.listError && google.rows.length === 0 ? (
              <div className="dj-table__state">
                {google.search.trim()
                  ? __('No Google Docs match this search.', TEXT_DOMAIN)
                  : google.chip === 'sharedDrive' && !google.currentFolder
                    ? __('No shared drives are available to this account.', TEXT_DOMAIN)
                    : __('No Google Docs here.', TEXT_DOMAIN)}
              </div>
            ) : null}
            {!google.listing && google.incompleteSearch ? (
              <div className="dj-table__state">{__('Google returned partial results. Narrow the search to see more.', TEXT_DOMAIN)}</div>
            ) : null}
            {!google.listing && google.hasMore ? (
              <div className="dj-table__more">
                <button className="dj-button dj-button--small" onClick={() => void google.loadMore()} type="button">{__('Load more', TEXT_DOMAIN)}</button>
              </div>
            ) : null}
          </div>
        </div>

        {otherFailures.length > 0 ? (
          <InlineNotice tone="error">
            {otherFailures.map((doc) => `${doc.name}: ${google.failures[doc.fileId]}`).join(' ')}
          </InlineNotice>
        ) : null}
        {google.submitError ? <InlineNotice tone="error">{google.submitError}</InlineNotice> : null}
      </div>

      <div className="dj-footer dj-google-footer">
        {isNew ? (
          <NativeSelect
            disabled={google.submitting}
            label={__('Create as', TEXT_DOMAIN)}
            onChange={google.setPostType}
            options={postTypeDraftOptions()}
            value={google.postType}
          />
        ) : null}
        <NativeSelect
          disabled={google.submitting}
          groups={layoutSelectGroups()}
          label={__('Layout', TEXT_DOMAIN)}
          onChange={google.setLayout}
          value={google.layout}
        />
        <span aria-live="polite" className="dj-footer__status">
          {google.progress || (isNew ? (
            <>
              <strong>{sprintf(_n('%d selected', '%d selected', count, TEXT_DOMAIN), count)}</strong>
              {count >= MAX_BATCH_DOCS
                ? sprintf(__(' · %d is the most per batch', TEXT_DOMAIN), MAX_BATCH_DOCS)
                : __(' · you\'ll land on Sources while they sync', TEXT_DOMAIN)}
            </>
          ) : count === 1 ? (
            <>
              <strong>{google.selected[0].name}</strong>
              {__(' · content stays until the first sync', TEXT_DOMAIN)}
            </>
          ) : __('Choose one Google Doc for this post.', TEXT_DOMAIN))}
        </span>
        <Dialog.Close asChild>
          <button className="dj-button" disabled={google.submitting} type="button">
            {__('Cancel', TEXT_DOMAIN)}
          </button>
        </Dialog.Close>
        <button
          className="dj-button dj-button--primary"
          disabled={!google.canSubmit}
          onClick={() => void (isNew ? google.createDrafts() : google.linkExisting())}
          type="button"
        >
          {primaryLabel}
        </button>
      </div>

      <ConfirmDialog
        busy={google.submitting}
        confirmLabel={__('Transfer sync responsibility', TEXT_DOMAIN)}
        description={__('This linked source currently uses another operator\'s Google connection for scheduled syncs. Transfer responsibility to your connected account? Existing WordPress content, revisions, and source settings are retained.', TEXT_DOMAIN)}
        open={google.ownershipTransferRequired}
        title={__('Transfer scheduled sync responsibility?', TEXT_DOMAIN)}
        onConfirm={() => google.linkExisting(true)}
        onOpenChange={google.setOwnershipTransferRequired}
      />
    </>
  );
};
