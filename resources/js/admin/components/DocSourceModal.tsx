import * as Dialog from '@radix-ui/react-dialog';
import { createElement, useEffect, useMemo, useState } from '@wordpress/element';

import { createSource, inspectDocument, type DocumentMetadata, type SyncResult } from '../api';
import { getAdminConfig } from '../config';
import { chooseGoogleDoc } from '../google-picker';
import { DocSourceAdvancedPanel } from './doc-source-advanced-panel';
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
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const config = useMemo(() => getAdminConfig(), []);
  const pickerReady = Boolean(config.hasClientId && config.hasPickerSettings);
  const advancedSourceMode: Exclude<SourceMode, 'picker'> = sourceMode === 'file_id' ? 'file_id' : 'url';

  useEffect(() => {
    if (!isOpen) {
      setSourceMode('picker');
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

  const toggleAdvanced = () => {
    const nextOpen = !advancedOpen;
    setAdvancedOpen(nextOpen);
    setMetadata(null);
    setError('');

    if (nextOpen) {
      setSourceMode('url');
      return;
    }

    setSourceMode('picker');
    setDocumentInput('');
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

          <div className="docsync-wp-modal__body">
            <div className="docsync-wp-picker-panel">
              <strong>Choose with Google Picker</strong>
              <p>{docSourceHelp.picker}</p>
              {!pickerReady ? <p className="docsync-wp-inline-warning">Finish Picker API key, Picker app ID, and OAuth client ID setup first.</p> : null}
            </div>

            <button className="button-link docsync-wp-advanced-toggle" onClick={toggleAdvanced} type="button">
              {advancedOpen ? 'Use Google Picker instead' : 'Advanced: paste URL or file ID'}
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
                sourceMode={advancedSourceMode}
              />
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
              disabled={busy || (sourceMode === 'picker' ? !pickerReady : documentInput.trim() === '')}
              onClick={inspect}
              type="button"
            >
              {sourceMode === 'picker' ? 'Choose with Picker' : 'Inspect'}
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
