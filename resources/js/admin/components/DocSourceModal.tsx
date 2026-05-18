import * as Dialog from '@radix-ui/react-dialog';
import { createElement, useEffect, useMemo, useState } from '@wordpress/element';

import { createSource, inspectDocument, type DocumentMetadata, type SyncResult } from '../api';
import { getAdminConfig } from '../config';
import { chooseGoogleDoc } from '../google-picker';
import { DocSourceTabs } from './DocSourceTabs';
import { docSourceHelp, type SourceMode } from './doc-source-modal-options';

type Target =
  | { mode: 'existing'; postId: number; postType?: string }
  | { mode: 'new'; postType: string };

type Props = {
  isOpen: boolean;
  target: Target | null;
  onClose: () => void;
  onCompleted: (result: SyncResult) => void;
};

export const DocSourceModal = ({ isOpen, target, onClose, onCompleted }: Props): JSX.Element | null => {
  const [sourceMode, setSourceMode] = useState<SourceMode>('picker');
  const [documentInput, setDocumentInput] = useState('');
  const [metadata, setMetadata] = useState<DocumentMetadata | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const config = useMemo(() => getAdminConfig(), []);

  useEffect(() => {
    if (!isOpen) {
      setSourceMode('picker');
      setDocumentInput('');
      setMetadata(null);
      setError('');
      setBusy(false);
    }
  }, [isOpen]);

  if (!isOpen || !target) {
    return null;
  }

  const inspect = async () => {
    setBusy(true);
    setError('');

    try {
      if (sourceMode === 'picker') {
        const picked = await chooseGoogleDoc(config);
        const inspected = await inspectDocument(picked.id, 'picker');
        setMetadata(inspected);
        setDocumentInput(picked.url || picked.id);
        return;
      }

      const inspected = await inspectDocument(documentInput, sourceMode);
      setMetadata(inspected);
    } catch (caught) {
      setMetadata(null);
      setError(caught instanceof Error ? caught.message : 'Could not inspect this Google Doc.');
    } finally {
      setBusy(false);
    }
  };

  const attach = async () => {
    if (!metadata) {
      setError('Inspect a Google Doc before linking it.');
      return;
    }

    setBusy(true);
    setError('');

    try {
      const result = await createSource({
        fileId: metadata.fileId,
        target: target.mode === 'existing'
          ? { mode: 'existing', postId: target.postId }
          : { mode: 'new', postType: target.postType },
        exportFormat: config.defaultExportFormat || 'markdown'
      });

      onCompleted(result);
      onClose();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Could not link this Google Doc.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog.Root open={isOpen} onOpenChange={(open) => {
      if (!open) {
        onClose();
      }
    }}>
      <Dialog.Portal>
        <Dialog.Overlay className="docsync-wp-modal-backdrop" />
        <Dialog.Content
          className="docsync-wp-modal"
          onPointerDownOutside={(event) => event.preventDefault()}
        >
          <div className="docsync-wp-modal__header">
            <div>
              <Dialog.Title asChild>
                <h2>Link Google Doc</h2>
              </Dialog.Title>
              <Dialog.Description asChild>
                <p>Google Docs is source of truth. Sync overwrites WordPress content.</p>
              </Dialog.Description>
            </div>
            <Dialog.Close asChild>
              <button className="button-link" type="button">
                Close
              </button>
            </Dialog.Close>
          </div>

          <DocSourceTabs
            onChange={(mode) => {
              setSourceMode(mode);
              setMetadata(null);
              setError('');
            }}
            sourceMode={sourceMode}
          />

          <div className="docsync-wp-modal__body">
            <p className="description">{docSourceHelp[sourceMode]}</p>

            {sourceMode !== 'picker' ? (
              <label className="docsync-wp-field">
                <span>{sourceMode === 'url' ? 'Google Docs URL' : 'Google Drive file ID'}</span>
                <input
                  className="regular-text"
                  onChange={(event) => setDocumentInput(event.currentTarget.value)}
                  placeholder={sourceMode === 'url' ? 'https://docs.google.com/document/d/...' : '1AbC...'}
                  type="text"
                  value={documentInput}
                />
              </label>
            ) : null}

            {error ? <div className="notice notice-error inline"><p>{error}</p></div> : null}

            {metadata ? (
              <div className="docsync-wp-doc-preview">
                <strong>{metadata.name}</strong>
                <span>{metadata.webViewLink || metadata.fileId}</span>
              </div>
            ) : null}
          </div>

          <div className="docsync-wp-modal__footer">
            <Dialog.Close asChild>
              <button className="button" disabled={busy} type="button">
                Cancel
              </button>
            </Dialog.Close>
            <button
              className="button button-secondary"
              disabled={busy || (sourceMode !== 'picker' && documentInput.trim() === '')}
              onClick={inspect}
              type="button"
            >
              {sourceMode === 'picker' ? 'Choose and inspect' : 'Inspect'}
            </button>
            <button
              className="button button-primary"
              disabled={busy || !metadata}
              onClick={attach}
              type="button"
            >
              {target.mode === 'new' ? 'Create synced draft' : 'Link to post'}
            </button>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
};
