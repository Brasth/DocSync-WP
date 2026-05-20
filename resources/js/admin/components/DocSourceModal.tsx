import * as Dialog from '@radix-ui/react-dialog';
import { createElement, useEffect, useMemo, useState } from '@wordpress/element';

import { createSource, inspectDocument, type DocumentMetadata, type DriveDocumentSummary, type SyncResult } from '../api';
import { getAdminConfig } from '../config';
import { DocSourceDriveBrowserPanel } from './doc-source-drive-browser-panel';
import { DocSourceAdvancedPanel } from './doc-source-advanced-panel';
import { type SourceMode } from './doc-source-modal-options';

type Target = { mode: 'existing'; postId: number; postType?: string } | { mode: 'new'; postType: string };
type Props = { isOpen: boolean; target: Target | null; onClose: () => void; onCompleted: (result: SyncResult) => void };

export const DocSourceModal = ({ isOpen, target, onClose, onCompleted }: Props): JSX.Element | null => {
  const [sourceMode, setSourceMode] = useState<SourceMode>('url');
  const [documentInput, setDocumentInput] = useState('');
  const [metadata, setMetadata] = useState<DocumentMetadata | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const config = useMemo(() => getAdminConfig(), []);

  useEffect(() => {
    if (!isOpen) {
      setSourceMode('url');
      setDocumentInput('');
      setMetadata(null);
      setError('');
      setBusy(false);
      setAdvancedOpen(false);
    }
  }, [isOpen]);

  if (!isOpen || !target) {
    return null;
  }

  const inspect = async () => {
    setBusy(true);
    setError('');

    try {
      const inspected = await inspectDocument(documentInput, sourceMode);
      setMetadata(inspected);
    } catch (caught) {
      setMetadata(null);
      setError(caught instanceof Error ? caught.message : 'Could not inspect this Google Doc.');
    } finally {
      setBusy(false);
    }
  };

  const toggleAdvanced = () => {
    const nextOpen = !advancedOpen;
    setAdvancedOpen(nextOpen);
    setMetadata(null);
    setError('');

    if (nextOpen) {
      setSourceMode('url');
      return;
    }

    setSourceMode('url');
    setDocumentInput('');
  };

  const selectDocument = (document: DriveDocumentSummary | null) => {
    setMetadata(document);
    setDocumentInput(document?.webViewLink || document?.fileId || '');
    setError('');
  };

  const attach = async () => {
    if (!metadata) {
      setError('Select or inspect a Google Doc before linking it.');
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
        exportFormat: config.defaultExportFormat || 'html_zip'
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

          <div className="docsync-wp-modal__body">
            {!advancedOpen ? (
              <DocSourceDriveBrowserPanel
                busy={busy}
                onSelect={selectDocument}
                selectedDocument={metadata}
              />
            ) : null}

            <button
              aria-expanded={advancedOpen}
              className="button button-secondary docsync-wp-advanced-toggle"
              onClick={toggleAdvanced}
              type="button"
            >
              {advancedOpen ? 'Browse Google Docs' : 'Paste URL or file ID'}
            </button>

            {advancedOpen ? (
              <DocSourceAdvancedPanel
                documentInput={documentInput}
                onInputChange={setDocumentInput}
                onModeChange={(mode) => {
                  setSourceMode(mode);
                  setMetadata(null);
                  setError('');
                }}
                sourceMode={sourceMode}
              />
            ) : null}

            {error ? <div className="notice notice-error inline"><p>{error}</p></div> : null}

            {metadata ? (
              <div className="docsync-wp-doc-preview">
                <span>Selected Google Doc</span>
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
            {advancedOpen ? (
              <button
                className="button button-secondary"
                disabled={busy || documentInput.trim() === ''}
                onClick={inspect}
                type="button"
              >
                Inspect
              </button>
            ) : null}
            <button
              className="button button-primary"
              disabled={busy || !metadata}
              onClick={attach}
              type="button"
            >
              {target.mode === 'new' ? 'Create synced draft' : 'Link source'}
            </button>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
};
