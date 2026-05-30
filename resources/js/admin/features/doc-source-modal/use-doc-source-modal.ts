import { speak } from '@wordpress/a11y';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
  createSource,
  inspectDocument,
  type DocumentMetadata,
  type DriveDocumentSummary,
  type SyncResult
} from '../../api';
import { getAdminConfig } from '../../config';
import { type SourceMode } from './doc-source-modal-options';

export type DocSourceTarget =
  | { mode: 'existing'; postId: number; postType?: string }
  | { mode: 'new'; postType: string };

type Args = {
  isOpen: boolean;
  target: DocSourceTarget | null;
  onClose: () => void;
  onCompleted: (result: SyncResult) => void;
};

export const useDocSourceModal = ({ isOpen, target, onClose, onCompleted }: Args) => {
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

  const inspect = async () => {
    setBusy(true);
    setError('');

    try {
      const inspected = await inspectDocument(documentInput, sourceMode);
      setMetadata(inspected);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not inspect this Google Doc.', 'docsync-wp');
      setMetadata(null);
      setError(message);
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const toggleAdvanced = () => {
    const nextOpen = !advancedOpen;
    setAdvancedOpen(nextOpen);
    setMetadata(null);
    setError('');
    setSourceMode('url');

    if (!nextOpen) {
      setDocumentInput('');
    }
  };

  const selectDocument = (document: DriveDocumentSummary | null) => {
    setMetadata(document);
    setDocumentInput(document?.webViewLink || document?.fileId || '');
    setError('');
  };

  const changeSourceMode = (mode: SourceMode) => {
    setSourceMode(mode);
    setMetadata(null);
    setError('');
  };

  const attach = async () => {
    if (!metadata || !target) {
      setError(__('Select or inspect a Google Doc before linking it.', 'docsync-wp'));
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
        exportFormat: config.defaultExportFormat || 'html_zip',
        syncMode: target.mode === 'new' ? 'background' : 'inline'
      });

      onCompleted(result);
      onClose();
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not link this Google Doc.', 'docsync-wp');
      setError(message);
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  return {
    advancedOpen,
    attach,
    busy,
    changeSourceMode,
    documentInput,
    error,
    inspect,
    metadata,
    selectDocument,
    setDocumentInput,
    sourceMode,
    toggleAdvanced
  };
};
