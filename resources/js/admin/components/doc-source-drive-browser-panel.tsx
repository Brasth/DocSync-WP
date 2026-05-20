import { createElement, useEffect, useState } from '@wordpress/element';

import { listDriveDocuments, type DriveDocumentSummary } from '../api';

type Props = {
  busy: boolean;
  selectedDocument: DriveDocumentSummary | null;
  onSelect: (document: DriveDocumentSummary | null) => void;
};

const pageSize = 20;

const formatModifiedTime = (value: string): string => {
  if (!value) {
    return 'Modified time unavailable';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return `Modified ${new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short'
  }).format(date)}`;
};

export const DocSourceDriveBrowserPanel = ({ busy, selectedDocument, onSelect }: Props): JSX.Element => {
  const [searchInput, setSearchInput] = useState('');
  const [activeSearch, setActiveSearch] = useState('');
  const [documents, setDocuments] = useState<DriveDocumentSummary[]>([]);
  const [nextPageToken, setNextPageToken] = useState('');
  const [incompleteSearch, setIncompleteSearch] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const loadDocuments = async (search: string, pageToken = '') => {
    setLoading(true);
    setError('');

    if (!pageToken) {
      setDocuments([]);
      setNextPageToken('');
      setIncompleteSearch(false);
    }

    try {
      const response = await listDriveDocuments({
        search,
        pageToken,
        pageSize
      });

      setDocuments((current) => pageToken ? [...current, ...response.documents] : response.documents);
      setNextPageToken(response.nextPageToken ?? '');
      setIncompleteSearch(Boolean(response.incompleteSearch));
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Could not load Google Docs.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadDocuments('').catch(() => undefined);
  }, []);

  const submitSearch = async () => {
    const search = searchInput.trim();
    setActiveSearch(search);
    onSelect(null);
    await loadDocuments(search);
  };

  const clearSearch = async () => {
    setSearchInput('');
    setActiveSearch('');
    onSelect(null);
    await loadDocuments('');
  };

  return (
    <div className="docsync-wp-drive-browser">
      <div>
        <strong>Choose from Google Drive</strong>
        <p>Browse Google Docs visible to your connected account, select one, then link it explicitly.</p>
      </div>

      <form
        className="docsync-wp-drive-browser__search"
        onSubmit={(event) => {
          event.preventDefault();
          submitSearch().catch(() => undefined);
        }}
      >
        <label>
          <span>Search Docs</span>
          <input
            className="regular-text"
            onChange={(event) => setSearchInput(event.currentTarget.value)}
            placeholder="Document name"
            type="search"
            value={searchInput}
          />
        </label>
        <button className="button" disabled={busy || loading} type="submit">
          Search
        </button>
        {activeSearch ? (
          <button className="button button-link" disabled={busy || loading} onClick={() => clearSearch().catch(() => undefined)} type="button">
            Clear
          </button>
        ) : null}
      </form>

      {error ? <div className="notice notice-error inline"><p>{error}</p></div> : null}
      {loading && documents.length === 0 ? <p className="description">Loading Google Docs...</p> : null}
      {!loading && !error && documents.length === 0 ? <p className="description">No Google Docs found.</p> : null}

      {documents.length > 0 ? (
        <ul className="docsync-wp-drive-browser__list">
          {documents.map((document) => {
            const selected = selectedDocument?.fileId === document.fileId;

            return (
              <li key={document.fileId}>
                <button
                  aria-pressed={selected}
                  className={selected ? 'is-selected' : ''}
                  disabled={busy}
                  onClick={() => onSelect(document)}
                  type="button"
                >
                  <strong>{document.name}</strong>
                  <span>{formatModifiedTime(document.modifiedTime)}</span>
                </button>
              </li>
            );
          })}
        </ul>
      ) : null}

      {incompleteSearch ? (
        <p className="docsync-wp-inline-warning">Google could not search every Drive location. Narrow the search if the Doc is missing.</p>
      ) : null}

      {nextPageToken ? (
        <div className="docsync-wp-drive-browser__more">
          <button className="button" disabled={busy || loading} onClick={() => loadDocuments(activeSearch, nextPageToken).catch(() => undefined)} type="button">
            {loading ? 'Loading...' : 'Load more'}
          </button>
        </div>
      ) : null}
    </div>
  );
};
