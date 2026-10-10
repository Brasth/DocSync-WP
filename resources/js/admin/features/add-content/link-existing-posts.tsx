/**
 * Link existing posts (Sources subview): choose where the Docs live, let the background
 * job inventory and match them, then confirm one-to-one pairs. Linking is attach-only:
 * no post content changes until that post's first sync. Manual choices need a fresh
 * compare; incomplete inventories never preselect.
 */
import * as Dialog from '@radix-ui/react-dialog';
import { speak } from '@wordpress/a11y';
import { createElement, Fragment, useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { listSharedDrives } from '../../api';
import { AdminApiError } from '../../api/client';
import {
  commitMatchJob,
  compareMatch,
  createIdempotencyKey,
  createMatchDoc,
  createMatchJob,
  getContinuationAuthUrl,
  getMatchJob,
  searchJourneyDriveItems
} from '../../api/journey-api';
import type { JourneyDriveItem, MatchCandidate, MatchCompare, MatchJob, MatchLocation, MatchRow } from '../../api/journey-types';
import type { AvailablePostType } from '../../config';
import { getAdminConfig } from '../../config';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { ensureLazyStyle } from '../doc-source-modal/lazy-drive-browser-panel';
import { CloseButton, Icon, InlineNotice, NativeSelect } from './add-content-dialog';
import { errorMessage, formatEditedDate, useStableId } from './use-add-content';

const TEXT_DOMAIN = 'brasth-document-sync-for-google-docs';
const POLL_INTERVAL_MS = 2500;
const PAGE_ROWS = 25;
const QUERY_VIEW = 'docsync_view';
const QUERY_JOB = 'docsync_job';
const MATCH_VIEW = 'match';
const UUID_PATTERN = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;
const scopeLabelKey = (jobId: string) => `docsync-wp-match-scope-${jobId}`;

/* ------------------------------------------------------------------ */
/* URL state                                                           */
/* ------------------------------------------------------------------ */

export const readMatchUrlState = (): { open: boolean; jobId: string | null } => {
  const params = new URL(window.location.href).searchParams;
  const jobId = params.get(QUERY_JOB);

  return {
    open: params.get(QUERY_VIEW) === MATCH_VIEW,
    jobId: jobId && UUID_PATTERN.test(jobId) ? jobId : null
  };
};

export const writeMatchUrlState = (state: { open: boolean; jobId: string | null }) => {
  const url = new URL(window.location.href);

  if (state.open) {
    url.searchParams.set(QUERY_VIEW, MATCH_VIEW);
  } else if (url.searchParams.get(QUERY_VIEW) === MATCH_VIEW) {
    url.searchParams.delete(QUERY_VIEW);
  }

  if (state.open && state.jobId) {
    url.searchParams.set(QUERY_JOB, state.jobId);
  } else {
    url.searchParams.delete(QUERY_JOB);
  }

  window.history.replaceState(window.history.state, '', url.toString());
};

/* ------------------------------------------------------------------ */
/* Row classification                                                  */
/* ------------------------------------------------------------------ */

type Filter = 'all' | 'confident' | 'check' | 'none';

type Selection = { fileId: string; name: string; modifiedTime: string; webViewLink: string; compareFingerprint?: string; manual: boolean };

const rowGroup = (row: MatchRow): Exclude<Filter, 'all'> | 'linked' => {
  if (row.state === 'alreadyLinked') {
    return 'linked';
  }

  if (row.state === 'preselected' || row.state === 'created') {
    return 'confident';
  }

  if (row.state === 'none') {
    return 'none';
  }

  return 'check';
};

const initialSelection = (row: MatchRow): Selection | null => {
  if (!row.selectedFileId || (row.state !== 'preselected' && row.state !== 'created')) {
    return null;
  }

  const candidate = row.candidates.find((item) => item.fileId === row.selectedFileId);

  return {
    fileId: row.selectedFileId,
    name: candidate?.name ?? row.postTitle,
    modifiedTime: candidate?.modifiedTime ?? '',
    webViewLink: candidate?.webViewLink ?? '',
    manual: false
  };
};

const postTypeLabel = (postType: string, postTypes: AvailablePostType[]): string => {
  return postTypes.find((type) => type.name === postType)?.label ?? postType;
};

/* ------------------------------------------------------------------ */
/* Folder chooser                                                      */
/* ------------------------------------------------------------------ */

type Scope = { location: MatchLocation; folderId: string; driveId: string; label: string };

const LOCATIONS: { id: MatchLocation; label: string }[] = [
  { id: 'myDrive', label: __('My Drive', TEXT_DOMAIN) },
  { id: 'sharedDrive', label: __('Shared drives', TEXT_DOMAIN) },
  { id: 'sharedWithMe', label: __('Shared with me', TEXT_DOMAIN) },
  { id: 'starred', label: __('Starred', TEXT_DOMAIN) },
  { id: 'recent', label: __('Recent', TEXT_DOMAIN) }
];

type Crumb = { folderId: string; driveId: string; name: string };

const FolderChooser = ({ value, onChange }: { value: Scope; onChange: (scope: Scope) => void }): JSX.Element => {
  const [location, setLocation] = useState<MatchLocation>(value.location);
  const [stack, setStack] = useState<Crumb[]>([]);
  const [folders, setFolders] = useState<{ id: string; name: string; driveId: string }[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const current = stack[stack.length - 1] ?? null;
  const locationLabel = LOCATIONS.find((item) => item.id === location)?.label ?? '';

  useEffect(() => {
    let active = true;

    setLoading(true);
    setError('');

    const load = async () => {
      try {
        if (location === 'sharedDrive' && !current) {
          const response = await listSharedDrives({ pageSize: 50 });

          if (active) {
            setFolders(response.drives.map((drive) => ({ id: drive.driveId, name: drive.name, driveId: drive.driveId })));
          }

          return;
        }

        if (location === 'recent' && !current) {
          if (active) {
            setFolders([]);
          }

          return;
        }

        const response = await searchJourneyDriveItems({
          location: current && location !== 'sharedDrive' ? 'myDrive' : location,
          folderId: current?.folderId ?? '',
          driveId: current?.driveId ?? '',
          pageSize: 50
        });

        if (active) {
          setFolders(response.items
            .filter((item: JourneyDriveItem) => item.itemType === 'folder')
            .map((item) => ({ id: item.fileId, name: item.name, driveId: current?.driveId ?? '' })));
        }
      } catch (caught) {
        if (active) {
          setError(errorMessage(caught, __('Could not list folders.', TEXT_DOMAIN)));
        }
      } finally {
        if (active) {
          setLoading(false);
        }
      }
    };

    void load();

    return () => {
      active = false;
    };
  }, [location, current]);

  useEffect(() => {
    const path = [locationLabel, ...stack.map((crumb) => crumb.name)].join(' / ');

    onChange({
      location,
      folderId: current?.folderId === current?.driveId ? '' : current?.folderId ?? '',
      driveId: current?.driveId ?? '',
      label: path
    });
    // Report every location change to the parent setup form.
  }, [location, stack]);

  const canUse = location !== 'sharedDrive' || Boolean(current);

  return (
    <div className="dj-folder-picker">
      <div aria-label={__('Drive location', TEXT_DOMAIN)} className="dj-chips" role="group">
        {LOCATIONS.map((item) => (
          <button
            aria-pressed={location === item.id}
            className="dj-chip"
            key={item.id}
            onClick={() => {
              setLocation(item.id);
              setStack([]);
            }}
            type="button"
          >
            {item.label}
          </button>
        ))}
      </div>
      <nav aria-label={__('Folder path', TEXT_DOMAIN)} className="dj-crumbs">
        <button className="dj-link" onClick={() => setStack([])} type="button">{locationLabel}</button>
        {stack.map((crumb, index) => (
          <Fragment key={`${crumb.folderId}-${index}`}>
            <span aria-hidden="true">/</span>
            {index === stack.length - 1
              ? <span aria-current="page">{crumb.name}</span>
              : <button className="dj-link" onClick={() => setStack(stack.slice(0, index + 1))} type="button">{crumb.name}</button>}
          </Fragment>
        ))}
      </nav>
      {error ? <InlineNotice tone="error">{error}</InlineNotice> : null}
      <div className="dj-folder-picker__list" role="list">
        {loading ? <div className="dj-table__state" role="status"><span aria-hidden="true" className="dj-spinner" /> {__('Loading folders…', TEXT_DOMAIN)}</div> : null}
        {!loading && folders.length === 0 && !error ? (
          <div className="dj-table__state">
            {location === 'recent'
              ? __('Recent Docs are searched directly; there are no folders to open.', TEXT_DOMAIN)
              : __('No folders here. Docs in this location and every folder below it are searched.', TEXT_DOMAIN)}
          </div>
        ) : null}
        {!loading ? folders.map((folder) => (
          <div key={folder.id} role="listitem">
            <button
              className="dj-folder-picker__item"
              onClick={() => setStack([...stack, { folderId: folder.id, driveId: folder.driveId, name: folder.name }])}
              type="button"
            >
              <Icon name={location === 'sharedDrive' && !current ? 'drive' : 'folder'} />
              {folder.name}
            </button>
          </div>
        )) : null}
      </div>
      {!canUse ? <p className="dj-match-meta">{__('Open a shared drive to search it.', TEXT_DOMAIN)}</p> : null}
    </div>
  );
};

/* ------------------------------------------------------------------ */
/* Compare and Find dialogs                                            */
/* ------------------------------------------------------------------ */

type CompareRequest = { row: MatchRow; fileId: string; name: string; modifiedTime: string; webViewLink: string };

const CompareDialog = ({ jobId, request, onClose, onConfirm }: {
  jobId: string;
  request: CompareRequest | null;
  onClose: () => void;
  onConfirm: (compare: MatchCompare, request: CompareRequest) => void;
}): JSX.Element | null => {
  const [compare, setCompare] = useState<MatchCompare | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!request) {
      setCompare(null);
      setError('');
      return;
    }

    let active = true;

    compareMatch(jobId, request.row.postId, request.fileId)
      .then((response) => {
        if (active) {
          setCompare(response);
        }
      })
      .catch((caught) => {
        if (active) {
          setError(errorMessage(caught, __('Could not compare this post and Doc.', TEXT_DOMAIN)));
        }
      });

    return () => {
      active = false;
    };
  }, [jobId, request]);

  if (!request) {
    return null;
  }

  return (
    <Dialog.Root open onOpenChange={(open) => {
      if (!open) {
        onClose();
      }
    }}>
      <Dialog.Portal>
        <Dialog.Overlay className="docsync-wp-journey-overlay" />
        <Dialog.Content className="docsync-wp-journey docsync-wp-journey-dialog docsync-wp-journey-dialog--compare">
          <div className="dj-header">
            <div className="dj-brand__copy" style={{ flex: '1 1 auto' }}>
              <Dialog.Title asChild><h2 className="dj-title">{__('Compare before linking', TEXT_DOMAIN)}</h2></Dialog.Title>
              <Dialog.Description asChild>
                <p className="dj-subtitle">{__('Linking keeps the post as it is. The Doc replaces its content at the first sync.', TEXT_DOMAIN)}</p>
              </Dialog.Description>
            </div>
            <CloseButton />
          </div>
          <div className="dj-body">
            {error ? <InlineNotice tone="error">{error}</InlineNotice> : null}
            {!compare && !error ? <div className="dj-state"><span aria-hidden="true" className="dj-spinner" />&nbsp;{__('Comparing…', TEXT_DOMAIN)}</div> : null}
            {compare ? (
              <>
                <div className="dj-compare">
                  <div className="dj-compare__side">
                    <p className="dj-eyebrow">{__('WordPress post', TEXT_DOMAIN)}</p>
                    <h3>{compare.post.title}</h3>
                    <p>{[sprintf(_n('%d word', '%d words', compare.post.wordCount, TEXT_DOMAIN), compare.post.wordCount), sprintf(__('edited %s', TEXT_DOMAIN), formatEditedDate(compare.post.modifiedAt))].join(' · ')}</p>
                    <p style={{ marginTop: 8 }}>{compare.post.excerpt}</p>
                  </div>
                  <div className="dj-compare__side">
                    <p className="dj-eyebrow">{__('Google Doc', TEXT_DOMAIN)}</p>
                    <h3><a href={compare.doc.webViewLink} rel="noopener noreferrer" target="_blank">{compare.doc.name}</a></h3>
                    <p>{[sprintf(_n('%d word', '%d words', compare.doc.wordCount, TEXT_DOMAIN), compare.doc.wordCount), sprintf(__('edited %s', TEXT_DOMAIN), formatEditedDate(compare.doc.modifiedTime))].join(' · ')}</p>
                    <p style={{ marginTop: 8 }}>{compare.doc.excerpt}</p>
                  </div>
                </div>
                <p className="dj-match-meta">
                  {[
                    compare.titleMatch ? __('Titles match.', TEXT_DOMAIN) : __('Titles differ.', TEXT_DOMAIN),
                    compare.leadingTokensMatch ? __('Opening paragraphs match.', TEXT_DOMAIN) : __('Opening paragraphs differ.', TEXT_DOMAIN),
                    sprintf(__('Similarity %d%%.', TEXT_DOMAIN), Math.round(compare.score * 100))
                  ].join(' ')}
                </p>
                <div aria-label={__('Text differences', TEXT_DOMAIN)} className="dj-diff">
                  {compare.diff.map((part, index) => {
                    if (part.op === 'delete') {
                      return <del key={index}>{part.text} </del>;
                    }

                    if (part.op === 'insert') {
                      return <ins key={index}>{part.text} </ins>;
                    }

                    return <span key={index}>{part.text} </span>;
                  })}
                </div>
              </>
            ) : null}
          </div>
          <div className="dj-footer">
            <span className="dj-footer__status dj-footer__status--start">{__('Removed words are struck through; words only in the Doc are highlighted.', TEXT_DOMAIN)}</span>
            <Dialog.Close asChild><button className="dj-button dj-button--compact" type="button">{__('Cancel', TEXT_DOMAIN)}</button></Dialog.Close>
            <button
              className="dj-button dj-button--primary dj-button--compact"
              disabled={!compare}
              onClick={() => compare && onConfirm(compare, request)}
              type="button"
            >
              {__('Use this Doc', TEXT_DOMAIN)}
            </button>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
};

const FindDocDialog = ({ row, onClose, onPick }: {
  row: MatchRow | null;
  onClose: () => void;
  onPick: (row: MatchRow, item: JourneyDriveItem) => void;
}): JSX.Element | null => {
  const [search, setSearch] = useState('');
  const [items, setItems] = useState<JourneyDriveItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const inputId = useStableId('dj-find-doc');

  useEffect(() => {
    if (row) {
      setSearch(row.postTitle);
    }
  }, [row]);

  useEffect(() => {
    if (!row) {
      return;
    }

    let active = true;
    const timer = window.setTimeout(() => {
      setLoading(true);
      setError('');
      searchJourneyDriveItems({ location: 'myDrive', search: search.trim(), globalSearch: search.trim() !== '', pageSize: 25 })
        .then((response) => {
          if (active) {
            setItems(response.items.filter((item) => item.itemType === 'document'));
          }
        })
        .catch((caught) => {
          if (active) {
            setError(errorMessage(caught, __('Could not search Google Drive.', TEXT_DOMAIN)));
          }
        })
        .finally(() => {
          if (active) {
            setLoading(false);
          }
        });
    }, 300);

    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [row, search]);

  if (!row) {
    return null;
  }

  return (
    <Dialog.Root open onOpenChange={(open) => {
      if (!open) {
        onClose();
      }
    }}>
      <Dialog.Portal>
        <Dialog.Overlay className="docsync-wp-journey-overlay" />
        <Dialog.Content className="docsync-wp-journey docsync-wp-journey-dialog docsync-wp-journey-dialog--connect" style={{ maxHeight: 'min(640px, calc(100vh - 32px))' }}>
          <div className="dj-header">
            <div className="dj-brand__copy" style={{ flex: '1 1 auto' }}>
              <Dialog.Title asChild><h2 className="dj-title">{__('Find a Doc', TEXT_DOMAIN)}</h2></Dialog.Title>
              <Dialog.Description asChild>
                <p className="dj-subtitle">{sprintf(__('Choose the Google Doc for “%s”.', TEXT_DOMAIN), row.postTitle)}</p>
              </Dialog.Description>
            </div>
            <CloseButton />
          </div>
          <div className="dj-body">
            <div className="dj-search dj-search--icon" style={{ flex: '0 0 auto', width: '100%' }}>
              <span className="dj-search__icon"><Icon name="search" size={14} /></span>
              <label className="screen-reader-text" htmlFor={inputId}>{__('Search all of Drive', TEXT_DOMAIN)}</label>
              <input className="dj-input" id={inputId} onChange={(event) => setSearch(event.currentTarget.value)} type="search" value={search} />
            </div>
            {error ? <InlineNotice tone="error">{error}</InlineNotice> : null}
            <div className="dj-folder-picker__list" role="list">
              {loading ? <div className="dj-table__state" role="status"><span aria-hidden="true" className="dj-spinner" /> {__('Searching…', TEXT_DOMAIN)}</div> : null}
              {!loading && items.length === 0 && !error ? <div className="dj-table__state">{__('No Google Docs match this search.', TEXT_DOMAIN)}</div> : null}
              {!loading ? items.map((item) => {
                const blocked = item.syncCompatibility?.canDownload === false || item.linked === true;

                return (
                  <div key={item.fileId} role="listitem">
                    <button className="dj-folder-picker__item" disabled={blocked} onClick={() => onPick(row, item)} type="button">
                      <Icon name={blocked ? 'lock' : 'doc'} />
                      <span style={{ minWidth: 0 }}>
                        <strong style={{ display: 'block', fontWeight: 600 }}>{item.name}</strong>
                        <span className="dj-match-meta">
                          {item.linked
                            ? __('Already linked to a post', TEXT_DOMAIN)
                            : [item.ownedByMe ? __('You', TEXT_DOMAIN) : item.ownerDisplayName ?? '', sprintf(__('edited %s', TEXT_DOMAIN), formatEditedDate(item.modifiedTime))].filter(Boolean).join(' · ')}
                        </span>
                      </span>
                    </button>
                  </div>
                );
              }) : null}
            </div>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
};

/* ------------------------------------------------------------------ */
/* Page                                                                */
/* ------------------------------------------------------------------ */

type Props = {
  initialJobId: string | null;
  postTypes: AvailablePostType[];
  onExit: () => void;
  onLinked: (count: number) => void;
};

const trimTrailingSlash = (value: string): string => value.replace(/\/$/, '');

export const LinkExistingPosts = ({ initialJobId, postTypes, onExit, onLinked }: Props): JSX.Element => {
  const config = getAdminConfig();
  const [jobId, setJobId] = useState<string | null>(initialJobId);
  const [job, setJob] = useState<MatchJob | null>(null);
  const [jobError, setJobError] = useState('');
  const [postType, setPostType] = useState(postTypes[0]?.name ?? 'post');
  const [scope, setScope] = useState<Scope>({ location: 'myDrive', folderId: '', driveId: '', label: __('My Drive', TEXT_DOMAIN) });
  const [scopeLabel, setScopeLabel] = useState('');
  const [starting, setStarting] = useState(false);
  const [selections, setSelections] = useState<Record<number, Selection>>({});
  const [initializedFor, setInitializedFor] = useState('');
  const [filter, setFilter] = useState<Filter>('all');
  const [search, setSearch] = useState('');
  const [showAll, setShowAll] = useState(false);
  const [rowErrors, setRowErrors] = useState<Record<number, string>>({});
  const [linkedPosts, setLinkedPosts] = useState<number[]>([]);
  const [compareRequest, setCompareRequest] = useState<CompareRequest | null>(null);
  const [findRow, setFindRow] = useState<MatchRow | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [committing, setCommitting] = useState(false);
  const [creatingDoc, setCreatingDoc] = useState<number | null>(null);
  const [notice, setNotice] = useState<{ tone: 'error' | 'warning' | 'info'; message: string; reconnect?: boolean } | null>(null);
  const searchId = useStableId('dj-match-search');
  const markUrl = config.pluginUrl ? `${trimTrailingSlash(config.pluginUrl)}/resources/images/brasth-mark.png` : '';

  useEffect(() => {
    config.docSourceModalStyleUrls.forEach((href, index) => ensureLazyStyle(href, `docsync-wp-doc-source-modal-style-${index}`));
  }, []);

  useEffect(() => {
    writeMatchUrlState({ open: true, jobId });

    if (jobId) {
      try {
        setScopeLabel(window.sessionStorage.getItem(scopeLabelKey(jobId)) ?? '');
      } catch {
        setScopeLabel('');
      }
    }
  }, [jobId]);

  const loadJob = useCallback(async (id: string) => {
    try {
      const next = await getMatchJob(id);

      setJob(next);
      setJobError('');
      return next;
    } catch (caught) {
      if (caught instanceof AdminApiError && (caught.code === 'docsync_wp_matching_job_not_found' || caught.code === 'docsync_wp_matching_job_expired')) {
        setJobId(null);
        setJob(null);
        setJobError(caught.code === 'docsync_wp_matching_job_expired'
          ? __('That matching run expired after 24 hours. Start a new one.', TEXT_DOMAIN)
          : __('That matching run is no longer available. Start a new one.', TEXT_DOMAIN));
        return null;
      }

      setJobError(errorMessage(caught, __('Could not read the matching progress.', TEXT_DOMAIN)));
      return null;
    }
  }, []);

  useEffect(() => {
    if (jobId) {
      void loadJob(jobId);
    }
  }, [jobId, loadJob]);

  const running = Boolean(job && ['queued', 'listing', 'running'].includes(job.status));

  useEffect(() => {
    if (!jobId || !running) {
      return;
    }

    const timer = window.setInterval(() => void loadJob(jobId), POLL_INTERVAL_MS);

    return () => window.clearInterval(timer);
  }, [jobId, running, loadJob]);

  /* Preselected and created rows start checked, once per job. */
  useEffect(() => {
    if (!job || job.status !== 'ready' || initializedFor === job.jobId) {
      return;
    }

    const next: Record<number, Selection> = {};

    job.rows.forEach((row) => {
      const selection = initialSelection(row);

      if (selection) {
        next[row.postId] = selection;
      }
    });

    setSelections(next);
    setInitializedFor(job.jobId);
    speak(sprintf(_n('%d confident match found.', '%d confident matches found.', Object.keys(next).length, TEXT_DOMAIN), Object.keys(next).length));
  }, [job, initializedFor]);

  const startJob = async () => {
    setStarting(true);
    setJobError('');
    setNotice(null);

    try {
      const created = await createMatchJob({
        posts: { postType },
        scope: { location: scope.location, folderId: scope.folderId, driveId: scope.driveId, fileIds: [] }
      });

      try {
        window.sessionStorage.setItem(scopeLabelKey(created.jobId), scope.label);
      } catch {
        // The hint falls back to a generic label when storage is unavailable.
      }

      setSelections({});
      setInitializedFor('');
      setRowErrors({});
      setLinkedPosts([]);
      setJob(created);
      setJobId(created.jobId);
    } catch (caught) {
      setJobError(errorMessage(caught, __('Could not start matching.', TEXT_DOMAIN)));
    } finally {
      setStarting(false);
    }
  };

  const rows = useMemo(() => (job?.rows ?? []).filter((row) => !linkedPosts.includes(row.postId)), [job, linkedPosts]);
  const counts = useMemo(() => ({
    all: rows.length,
    confident: rows.filter((row) => rowGroup(row) === 'confident').length,
    check: rows.filter((row) => rowGroup(row) === 'check').length,
    none: rows.filter((row) => rowGroup(row) === 'none').length
  }), [rows]);
  const visibleRows = rows.filter((row) => {
    const group = rowGroup(row);

    if (filter !== 'all' && group !== filter) {
      return false;
    }

    return search.trim() === '' || row.postTitle.toLowerCase().includes(search.trim().toLowerCase());
  });
  const shownRows = showAll ? visibleRows : visibleRows.slice(0, PAGE_ROWS);
  const selectedPairs = Object.entries(selections)
    .map(([postId, selection]) => ({ postId: Number(postId), selection }))
    .filter((pair) => rows.some((row) => row.postId === pair.postId));
  const fileUse = selectedPairs.reduce<Record<string, number>>((use, pair) => {
    use[pair.selection.fileId] = (use[pair.selection.fileId] ?? 0) + 1;
    return use;
  }, {});
  const duplicates = Object.entries(fileUse).filter(([, count]) => count > 1).map(([fileId]) => fileId);
  const confidentRows = rows.filter((row) => rowGroup(row) === 'confident');
  const allConfidentChecked = confidentRows.length > 0 && confidentRows.every((row) => selections[row.postId]);

  const select = (postId: number, selection: Selection | null) => {
    setSelections((current) => {
      const next = { ...current };

      if (selection) {
        next[postId] = selection;
      } else {
        delete next[postId];
      }

      return next;
    });
  };

  const toggleRow = (row: MatchRow) => {
    if (selections[row.postId]) {
      select(row.postId, null);
      return;
    }

    const selection = initialSelection(row);

    if (selection) {
      select(row.postId, selection);
    }
  };

  const toggleAllConfident = () => {
    confidentRows.forEach((row) => select(row.postId, allConfidentChecked ? null : initialSelection(row)));
  };

  const requestCompare = (row: MatchRow, candidate: Pick<MatchCandidate, 'fileId' | 'name' | 'modifiedTime' | 'webViewLink'>) => {
    setCompareRequest({ row, fileId: candidate.fileId, name: candidate.name, modifiedTime: candidate.modifiedTime, webViewLink: candidate.webViewLink });
  };

  const requestDriveFile = async () => {
    if (!jobId) {
      return;
    }

    try {
      const response = await getContinuationAuthUrl({ scopeSet: 'driveFile', returnTo: 'sources', resumeKind: 'matching', resumeId: jobId });

      window.location.assign(response.authUrl);
    } catch (caught) {
      setNotice({ tone: 'error', message: errorMessage(caught, __('Could not start the Google permission request.', TEXT_DOMAIN)) });
    }
  };

  const createDoc = async (row: MatchRow) => {
    if (!jobId) {
      return;
    }

    setCreatingDoc(row.postId);
    setNotice(null);

    try {
      const response = await createMatchDoc(jobId, createIdempotencyKey(), row.postId, '');
      const updated = response.row;

      setJob((current) => current ? { ...current, rows: current.rows.map((item) => item.postId === updated.postId ? updated : item) } : current);

      const selection = initialSelection(updated);

      if (selection) {
        select(updated.postId, selection);
      }

      speak(sprintf(__('Google Doc created for %s.', TEXT_DOMAIN), row.postTitle));
    } catch (caught) {
      if (caught instanceof AdminApiError && caught.code === 'docsync_wp_google_write_scope_required') {
        setNotice({ tone: 'warning', message: caught.message, reconnect: true });
        return;
      }

      setRowErrors((current) => ({ ...current, [row.postId]: errorMessage(caught, __('Could not create a Doc from this post.', TEXT_DOMAIN)) }));
    } finally {
      setCreatingDoc(null);
    }
  };

  const commit = async () => {
    if (!jobId || selectedPairs.length === 0 || duplicates.length > 0) {
      return;
    }

    setConfirmOpen(false);
    setCommitting(true);
    setNotice(null);

    try {
      const response = await commitMatchJob(jobId, createIdempotencyKey(), selectedPairs.map((pair) => ({
        postId: pair.postId,
        fileId: pair.selection.fileId,
        compareFingerprint: pair.selection.compareFingerprint
      })));
      const linked = response.results.filter((result) => result.status === 'linked').map((result) => result.postId);
      const failed = response.results.filter((result) => result.status === 'failed');

      // Linked rows leave the table; failed rows stay with their reason.
      setLinkedPosts((current) => [...current, ...linked]);
      setSelections((current) => {
        const next = { ...current };
        linked.forEach((postId) => delete next[postId]);
        return next;
      });
      setRowErrors(Object.fromEntries(failed.map((result) => [result.postId, result.error?.message ?? __('Could not link this post.', TEXT_DOMAIN)])));

      if (linked.length > 0) {
        onLinked(linked.length);
      }

      const message = failed.length > 0
        ? sprintf(__('%1$d linked, %2$d could not be linked. They stay in the table with the reason.', TEXT_DOMAIN), linked.length, failed.length)
        : sprintf(_n('%d post linked. Its content stays until its first sync.', '%d posts linked. Their content stays until their first sync.', linked.length, TEXT_DOMAIN), linked.length);

      setNotice({ tone: failed.length > 0 ? 'warning' : 'info', message });
      speak(message, failed.length > 0 ? 'assertive' : 'polite');
    } catch (caught) {
      if (caught instanceof AdminApiError && caught.code === 'docsync_wp_matching_compare_required') {
        setNotice({ tone: 'warning', message: __('A chosen Doc changed since you compared it. Compare it again, then link.', TEXT_DOMAIN) });
        await loadJob(jobId);
        return;
      }

      setNotice({ tone: 'error', message: errorMessage(caught, __('Could not link these posts.', TEXT_DOMAIN)) });
    } finally {
      setCommitting(false);
    }
  };

  const renderDocCell = (row: MatchRow): JSX.Element => {
    const selection = selections[row.postId];

    if (row.state === 'alreadyLinked') {
      return <span className="dj-match-meta">{__('Already linked to a Google Doc', TEXT_DOMAIN)}</span>;
    }

    if (selection) {
      return (
        <div className="dj-match-doc">
          <span aria-hidden="true" className="dj-row__icon"><Icon name="doc" /></span>
          <span style={{ minWidth: 0 }}>
            <strong>{selection.webViewLink ? <a href={selection.webViewLink} rel="noopener noreferrer" style={{ color: 'inherit' }} target="_blank">{selection.name}</a> : selection.name}</strong>
            <span className="dj-match-meta">
              {[
                row.matchKind === 'created' && !selection.manual ? __('New Doc in Imported from WordPress', TEXT_DOMAIN) : '',
                selection.modifiedTime ? sprintf(__('edited %s', TEXT_DOMAIN), formatEditedDate(selection.modifiedTime)) : '',
                duplicates.includes(selection.fileId) ? __('also chosen for another post', TEXT_DOMAIN) : ''
              ].filter(Boolean).join(' · ')}
            </span>
          </span>
        </div>
      );
    }

    if (row.candidates.length > 0) {
      const first = row.candidates[0];

      return (
        <span className="dj-candidate-select">
          <span aria-hidden="true" className="dj-candidate-select__face">
            <span className="dj-row__icon"><Icon name="doc" /></span>
            <span style={{ minWidth: 0 }}>
              <strong style={{ display: 'block', fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{first.name}</strong>
              <span className="dj-match-meta">
                {row.candidates.length > 1
                  ? sprintf(__('%d candidates · pick one', TEXT_DOMAIN), row.candidates.length)
                  : __('1 candidate · compare first', TEXT_DOMAIN)}
              </span>
            </span>
            <svg aria-hidden="true" height="12" viewBox="0 0 12 12" width="12"><path d="M3 4.5 6 7.5 9 4.5" fill="none" stroke="currentColor" strokeLinecap="round" strokeWidth="1.4" /></svg>
          </span>
          <select
            aria-label={sprintf(__('Choose the Google Doc for %s', TEXT_DOMAIN), row.postTitle)}
            onChange={(event) => {
              const candidate = row.candidates.find((item) => item.fileId === event.currentTarget.value);

              event.currentTarget.value = '';

              if (candidate) {
                requestCompare(row, candidate);
              }
            }}
            value=""
          >
            <option value="">{__('Pick a candidate to compare…', TEXT_DOMAIN)}</option>
            {row.candidates.map((candidate) => (
              <option disabled={candidate.linkedPostId !== null && candidate.linkedPostId !== row.postId} key={candidate.fileId} value={candidate.fileId}>
                {`${candidate.name} · ${candidate.kind === 'approximate' ? sprintf(__('%d%% similar', TEXT_DOMAIN), Math.round(candidate.score * 100)) : candidate.kind === 'exactTitle' ? __('same title', TEXT_DOMAIN) : __('same opening', TEXT_DOMAIN)}${candidate.linkedPostId !== null ? ` · ${__('linked elsewhere', TEXT_DOMAIN)}` : ''}`}
              </option>
            ))}
          </select>
        </span>
      );
    }

    return (
      <button className="dj-find-doc" onClick={() => setFindRow(row)} type="button">
        <Icon name="search" size={14} />
        {__('Find a Doc…', TEXT_DOMAIN)}
      </button>
    );
  };

  const renderMatchCell = (row: MatchRow): JSX.Element => {
    const selection = selections[row.postId];
    const group = rowGroup(row);

    if (group === 'linked') {
      return <span className="dj-match-score dj-match-score--none"><span className="dj-match-score__bar"><span /></span>{__('Linked', TEXT_DOMAIN)}</span>;
    }

    if (selection?.manual) {
      return <span className="dj-match-score dj-match-score--confident"><span className="dj-match-score__bar"><span /></span>{__('Compared', TEXT_DOMAIN)}</span>;
    }

    if (group === 'confident') {
      const label = row.matchKind === 'exactLeadingTokens'
        ? __('Content', TEXT_DOMAIN)
        : row.matchKind === 'created' ? __('New Doc', TEXT_DOMAIN) : __('Same title', TEXT_DOMAIN);

      return <span className="dj-match-score dj-match-score--confident"><span className="dj-match-score__bar"><span /></span>{label}</span>;
    }

    if (group === 'check') {
      return <span className="dj-match-score dj-match-score--check"><span className="dj-match-score__bar"><span /></span>{__('Check', TEXT_DOMAIN)}</span>;
    }

    return <span className="dj-match-score dj-match-score--none"><span className="dj-match-score__bar"><span /></span>{__('No match', TEXT_DOMAIN)}</span>;
  };

  const renderAfterCell = (row: MatchRow): JSX.Element => {
    const error = rowErrors[row.postId];
    const selection = selections[row.postId];
    const group = rowGroup(row);

    if (error) {
      return <span className="dj-match-after dj-match-after--failed">{error}</span>;
    }

    if (group === 'linked') {
      return <span className="dj-match-after">{__('Already syncing from a Doc.', TEXT_DOMAIN)}</span>;
    }

    if (selection) {
      return <span className="dj-match-after">{__('Post keeps its content. Next sync: scheduled.', TEXT_DOMAIN)}</span>;
    }

    if (group === 'check') {
      const first = row.candidates[0];

      return (
        <span className="dj-match-after">
          {row.preselectBlocked === 'inventoryIncomplete'
            ? __('Search was incomplete. ', TEXT_DOMAIN)
            : row.state === 'conflict' ? __('Matches another post too. ', TEXT_DOMAIN) : __('Title differs. ', TEXT_DOMAIN)}
          {first ? <button className="dj-link" onClick={() => requestCompare(row, first)} type="button">{__('Compare', TEXT_DOMAIN)}</button> : null}
          {__(' before linking.', TEXT_DOMAIN)}
        </span>
      );
    }

    return (
      <span className="dj-match-after">
        {__('Leave unlinked, or ', TEXT_DOMAIN)}
        <button className="dj-link" disabled={creatingDoc !== null} onClick={() => void createDoc(row)} type="button">
          {creatingDoc === row.postId ? __('creating a Doc…', TEXT_DOMAIN) : __('create a Doc from this post', TEXT_DOMAIN)}
        </button>
        .
      </span>
    );
  };

  const inventory = job?.inventory;
  const linkCount = selectedPairs.length;

  return (
    <div className="docsync-wp-journey dj-match-page">
      <header className="dj-match-header">
        <div className="dj-match-header__brand">
          {markUrl ? <img alt="" aria-hidden="true" height="40" src={markUrl} width="40" /> : null}
          <div>
            <strong>{__('Document Sync', TEXT_DOMAIN)}</strong>
            <span>{__('Google Docs → WordPress', TEXT_DOMAIN)}</span>
          </div>
        </div>
        <nav aria-label={__('Document Sync', TEXT_DOMAIN)} className="dj-match-nav">
          <a aria-current="page" href="admin.php?page=brasth-document-sync-for-google-docs-sources">{__('Sources', TEXT_DOMAIN)}</a>
          <a href="admin.php?page=brasth-document-sync-for-google-docs-folders">{__('Folders', TEXT_DOMAIN)}</a>
          <a href="admin.php?page=brasth-document-sync-for-google-docs-logs">{__('Activity', TEXT_DOMAIN)}</a>
          <a href="admin.php?page=brasth-document-sync-for-google-docs">{__('Settings', TEXT_DOMAIN)}</a>
        </nav>
      </header>

      <div className="dj-match-main">
        <div>
          <nav aria-label={__('Breadcrumb', TEXT_DOMAIN)} className="dj-breadcrumb">
            <button className="dj-link" onClick={onExit} type="button">{__('Sources', TEXT_DOMAIN)}</button>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{__('Link existing posts', TEXT_DOMAIN)}</span>
          </nav>
          <div className="dj-match-top">
            <div>
              <h1>{__('Link existing posts to their Google Docs', TEXT_DOMAIN)}</h1>
              <p>{__('We matched your unlinked posts to Docs by title and content. Confirm the ones that look right; linking never changes a post until its first sync.', TEXT_DOMAIN)}</p>
            </div>
            <div className="dj-match-actions">
              {job ? (
                <span className="dj-folder-hint">
                  {__('Searched ', TEXT_DOMAIN)}
                  <strong>{scopeLabel || __('your Drive', TEXT_DOMAIN)}</strong>
                  {' · '}
                  <button className="dj-link" disabled={committing} onClick={() => {
                    setJob(null);
                    setJobId(null);
                  }} type="button">{__('change folder', TEXT_DOMAIN)}</button>
                </span>
              ) : null}
              <button className="dj-button" onClick={onExit} type="button">{__('Skip for now', TEXT_DOMAIN)}</button>
              {job ? (
                <button
                  className="dj-button dj-button--primary"
                  disabled={committing || linkCount === 0 || duplicates.length > 0 || job.status !== 'ready'}
                  onClick={() => setConfirmOpen(true)}
                  type="button"
                >
                  {committing
                    ? __('Linking…', TEXT_DOMAIN)
                    : sprintf(_n('Link %d matched post', 'Link %d matched posts', linkCount, TEXT_DOMAIN), linkCount)}
                </button>
              ) : null}
            </div>
          </div>
        </div>

        {jobError ? <InlineNotice tone="error">{jobError}</InlineNotice> : null}
        {notice ? (
          <InlineNotice
            actionLabel={notice.reconnect ? __('Allow Google Drive access', TEXT_DOMAIN) : undefined}
            onAction={notice.reconnect ? () => void requestDriveFile() : undefined}
            tone={notice.tone}
          >
            {notice.message}
          </InlineNotice>
        ) : null}
        {duplicates.length > 0 ? <InlineNotice tone="warning">{__('The same Google Doc is chosen for more than one post. Each Doc can link to one post only.', TEXT_DOMAIN)}</InlineNotice> : null}

        <section className="dj-match-card">
          {!job ? (
            <div className="dj-match-setup">
              <div className="dj-match-setup__row">
                <span className="dj-aside__field">
                  <span className="dj-aside__label">{__('Which posts?', TEXT_DOMAIN)}</span>
                  <NativeSelect
                    label={__('Post type', TEXT_DOMAIN)}
                    onChange={setPostType}
                    options={postTypes.map((type) => ({ value: type.name, label: sprintf(__('Unlinked %s', TEXT_DOMAIN), type.label) }))}
                    value={postType}
                  />
                </span>
              </div>
              <span className="dj-aside__label">{__('Where are the Docs? Every folder inside is searched too.', TEXT_DOMAIN)}</span>
              <FolderChooser onChange={setScope} value={scope} />
              <div className="dj-match-setup__row">
                <button
                  className="dj-button dj-button--primary"
                  disabled={starting || (scope.location === 'sharedDrive' && !scope.driveId)}
                  onClick={() => void startJob()}
                  type="button"
                >
                  {starting ? __('Starting…', TEXT_DOMAIN) : sprintf(__('Find matches in %s', TEXT_DOMAIN), scope.label)}
                </button>
                <span className="dj-match-meta">{__('Looks at up to 100 recent unlinked posts and 200 Docs.', TEXT_DOMAIN)}</span>
              </div>
            </div>
          ) : running || job.status === 'failed' ? (
            <div className="dj-inventory" role="status">
              {job.status === 'failed' ? (
                <InlineNotice tone="error">{job.error?.message || __('Matching stopped with an error.', TEXT_DOMAIN)}</InlineNotice>
              ) : (
                <>
                  <strong>
                    {job.status === 'running'
                      ? sprintf(__('Reading Docs · %1$d of %2$d', TEXT_DOMAIN), job.progress.processed, job.progress.total)
                      : sprintf(
                        /* translators: 1: Docs found, 2: folders searched. */
                        __('Searching Drive · %1$d Docs in %2$d folders', TEXT_DOMAIN),
                        inventory?.docsFound ?? 0,
                        inventory?.foldersVisited ?? 0
                      )}
                  </strong>
                  <div className="dj-progress">
                    <div className="dj-progress__track">
                      <div
                        className={`dj-progress__bar${job.status === 'running' && job.progress.total > 0 ? '' : ' dj-progress__bar--indeterminate'}`}
                        style={job.status === 'running' && job.progress.total > 0 ? { width: `${Math.round((job.progress.processed / job.progress.total) * 100)}%` } : undefined}
                      />
                    </div>
                  </div>
                  <span className="dj-match-meta">{__('You can leave this page; matching continues in the background for 24 hours.', TEXT_DOMAIN)}</span>
                </>
              )}
            </div>
          ) : (
            <>
              {inventory && !inventory.complete ? (
                <div style={{ padding: '14px 14px 0' }}>
                  <InlineNotice tone="warning">
                    {__('The Drive search did not finish, so nothing was preselected. You can still choose Docs yourself. ', TEXT_DOMAIN)}
                    {inventory.warnings.map((warning) => warning.message).join(' ')}
                  </InlineNotice>
                </div>
              ) : null}
              <div className="dj-match-toolbar">
                <div aria-label={__('Filter matches', TEXT_DOMAIN)} className="dj-chips" role="group">
                  {([
                    ['all', __('All', TEXT_DOMAIN), counts.all],
                    ['confident', __('Confident', TEXT_DOMAIN), counts.confident],
                    ['check', __('Check these', TEXT_DOMAIN), counts.check],
                    ['none', __('No match', TEXT_DOMAIN), counts.none]
                  ] as [Filter, string, number][]).map(([id, label, count]) => (
                    <button aria-pressed={filter === id} className="dj-chip" key={id} onClick={() => setFilter(id)} type="button">
                      {label} <span className="dj-chip__count">{count}</span>
                    </button>
                  ))}
                </div>
                <div className="dj-search dj-search--icon">
                  <span className="dj-search__icon"><Icon name="search" size={14} /></span>
                  <label className="screen-reader-text" htmlFor={searchId}>{__('Search posts', TEXT_DOMAIN)}</label>
                  <input className="dj-input" id={searchId} onChange={(event) => setSearch(event.currentTarget.value)} placeholder={__('Search posts', TEXT_DOMAIN)} type="search" value={search} />
                </div>
              </div>
              <div style={{ overflowX: 'auto' }}>
                <table className="dj-match-table">
                  <thead>
                    <tr>
                      <th className="dj-col-check">
                        <span className="dj-checkbox-hit">
                          <input
                            aria-label={__('Select every confident match', TEXT_DOMAIN)}
                            checked={allConfidentChecked}
                            className="dj-checkbox"
                            disabled={confidentRows.length === 0 || committing}
                            onChange={toggleAllConfident}
                            type="checkbox"
                          />
                        </span>
                      </th>
                      <th>{__('WordPress post', TEXT_DOMAIN)}</th>
                      <th>{__('Google Doc', TEXT_DOMAIN)}</th>
                      <th className="dj-col-match">{__('Match', TEXT_DOMAIN)}</th>
                      <th className="dj-col-after">{__('After linking', TEXT_DOMAIN)}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {shownRows.map((row) => {
                      const group = rowGroup(row);
                      const selection = selections[row.postId];
                      const canToggle = Boolean(selection) || group === 'confident';

                      return (
                        <tr className={`${group === 'check' && !selection ? 'dj-match-row--check' : ''}${group === 'linked' ? ' dj-match-row--disabled' : ''}`} key={row.postId}>
                          <td className="dj-col-check">
                            <span className="dj-checkbox-hit">
                              <input
                                aria-label={sprintf(__('Link %s', TEXT_DOMAIN), row.postTitle)}
                                checked={Boolean(selection)}
                                className="dj-checkbox"
                                disabled={!canToggle || committing}
                                onChange={() => toggleRow(row)}
                                type="checkbox"
                              />
                            </span>
                          </td>
                          <td>
                            <span className="dj-match-post">
                              <strong><a href={row.editUrl}>{row.postTitle}</a></strong>
                              <span className="dj-match-meta">{postTypeLabel(row.postType, postTypes)}</span>
                            </span>
                          </td>
                          <td>{renderDocCell(row)}</td>
                          <td>{renderMatchCell(row)}</td>
                          <td>{renderAfterCell(row)}</td>
                        </tr>
                      );
                    })}
                    {shownRows.length === 0 ? (
                      <tr>
                        <td colSpan={5}>
                          <span className="dj-match-meta">
                            {rows.length === 0
                              ? __('No unlinked posts to match. Every post of this type already has a Google Doc or none were found.', TEXT_DOMAIN)
                              : __('No posts in this view.', TEXT_DOMAIN)}
                          </span>
                        </td>
                      </tr>
                    ) : null}
                  </tbody>
                </table>
              </div>
              <div className="dj-match-footer">
                <span>
                  {sprintf(__('Showing %1$d of %2$d', TEXT_DOMAIN), shownRows.length, visibleRows.length)}
                  {!showAll && visibleRows.length > shownRows.length ? (
                    <>
                      {' · '}
                      <button className="dj-link" onClick={() => setShowAll(true)} type="button">{__('show all', TEXT_DOMAIN)}</button>
                    </>
                  ) : null}
                </span>
                <span>{__('Matching looks at titles first, then the opening paragraphs. Nothing is synced until you link.', TEXT_DOMAIN)}</span>
              </div>
            </>
          )}
        </section>
      </div>

      {jobId ? (
        <CompareDialog
          jobId={jobId}
          onClose={() => setCompareRequest(null)}
          onConfirm={(compare, request) => {
            select(request.row.postId, {
              fileId: request.fileId,
              name: request.name,
              modifiedTime: request.modifiedTime,
              webViewLink: request.webViewLink,
              compareFingerprint: compare.compareFingerprint,
              manual: true
            });
            setRowErrors((current) => {
              const next = { ...current };
              delete next[request.row.postId];
              return next;
            });
            setCompareRequest(null);
          }}
          request={compareRequest}
        />
      ) : null}
      <FindDocDialog
        onClose={() => setFindRow(null)}
        onPick={(row, item) => {
          setFindRow(null);
          requestCompare(row, item);
        }}
        row={findRow}
      />
      <ConfirmDialog
        busy={committing}
        confirmLabel={sprintf(_n('Link %d post', 'Link %d posts', linkCount, TEXT_DOMAIN), linkCount)}
        description={__('Each post is linked to its chosen Google Doc. Nothing in these posts changes now; the Doc replaces the content at the first sync, on the normal schedule or when you press Sync.', TEXT_DOMAIN)}
        open={confirmOpen}
        title={sprintf(_n('Link %d post to its Google Doc?', 'Link %d posts to their Google Docs?', linkCount, TEXT_DOMAIN), linkCount)}
        onConfirm={() => void commit()}
        onOpenChange={setConfirmOpen}
      />
    </div>
  );
};
