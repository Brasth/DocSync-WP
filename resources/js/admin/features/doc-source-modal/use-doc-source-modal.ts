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
import { type DocSourceOutputType, type DocSourceUiMode } from './doc-source-modal-options';

export type DocSourceTarget =
  | {
      mode: 'existing';
      postId: number;
      postType?: string;
      elementorSync?: boolean | null;
      elementorPreset?: string | null;
      layoutPreset?: string | null;
    }
  | { mode: 'new'; postType: string };

type Args = {
  isOpen: boolean;
  target: DocSourceTarget | null;
  onClose: () => void;
  onCompleted: (result: SyncResult) => void;
};

export const useDocSourceModal = ({ isOpen, target, onClose, onCompleted }: Args) => {
  const [uiMode, setUiMode] = useState<DocSourceUiMode>('browse');
  const [documentInput, setDocumentInput] = useState('');
  const [metadata, setMetadata] = useState<DocumentMetadata | null>(null);
  const [layoutPreset, setLayoutPreset] = useState('');
  const [elementorPreset, setElementorPreset] = useState('');
  const [outputType, setOutputType] = useState<DocSourceOutputType>('blocks');
  const [outputTypeTouched, setOutputTypeTouched] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const config = useMemo(() => getAdminConfig(), []);
  const canChooseElementor = Boolean(
    config.elementorAvailable
    && config.elementorSyncEnabled
    && config.availableElementorLayoutPresets.length > 0
  );

  useEffect(() => {
    if (!isOpen) {
      setUiMode('browse');
      setDocumentInput('');
      setMetadata(null);
      setLayoutPreset('');
      setElementorPreset('');
      setOutputType('blocks');
      setOutputTypeTouched(false);
      setError('');
      setBusy(false);
    }
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || !target) {
      return;
    }

    setOutputType(canChooseElementor && target.mode === 'existing' && target.elementorSync ? 'elementor' : 'blocks');
    setOutputTypeTouched(false);
    setLayoutPreset(target.mode === 'existing' ? target.layoutPreset ?? '' : '');
    setElementorPreset(target.mode === 'existing' ? target.elementorPreset ?? '' : '');
  }, [canChooseElementor, isOpen, target]);

  const changeOutputType = (value: DocSourceOutputType) => {
    setOutputType(value);
    setOutputTypeTouched(true);
  };

  const inspect = async () => {
    if (uiMode === 'browse') {
      return;
    }

    setBusy(true);
    setError('');

    try {
      const inspected = await inspectDocument(documentInput, uiMode);
      setMetadata(inspected);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not inspect this Google Doc.', 'brasth-document-sync-for-google-docs');
      setMetadata(null);
      setError(message);
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const changeSourceMode = (mode: DocSourceUiMode) => {
    setUiMode(mode);
    setMetadata(null);
    setError('');

    if (mode === 'browse') {
      setDocumentInput('');
    }
  };

  const selectDocument = (document: DriveDocumentSummary | null) => {
    setMetadata(document);
    setDocumentInput(document?.webViewLink || document?.fileId || '');
    setError('');
  };

  const attach = async () => {
    if (!metadata || !target) {
      setError(__('Select or inspect a Google Doc before linking it.', 'brasth-document-sync-for-google-docs'));
      return;
    }

    if (metadata.syncCompatibility?.canDownload === false) {
      const message = metadata.syncCompatibility.warningMessage || __('Google says this Doc cannot be downloaded by the connected account.', 'brasth-document-sync-for-google-docs');
      setError(message);
      speak(message, 'assertive');
      return;
    }

    setBusy(true);
    setError('');

    try {
      const useElementorOutput = canChooseElementor && outputType === 'elementor';
      const useExplicitOutputChoice = canChooseElementor && (
        target.mode === 'new'
        || typeof target.elementorSync === 'boolean'
        || outputTypeTouched
      );
      const preserveLegacyElementorPreset = Boolean(
        useElementorOutput
        && target.mode === 'existing'
        && target.elementorPreset === null
        && elementorPreset === ''
      );
      const result = await createSource({
        fileId: metadata.fileId,
        target: target.mode === 'existing'
          ? { mode: 'existing', postId: target.postId }
          : { mode: 'new', postType: target.postType },
        exportFormat: config.defaultExportFormat || 'html_zip',
        elementorSync: useExplicitOutputChoice ? useElementorOutput : undefined,
        elementorPreset: useElementorOutput && !preserveLegacyElementorPreset ? elementorPreset : undefined,
        syncMode: 'background',
        layoutPreset: useElementorOutput ? undefined : layoutPreset
      });

      onCompleted(result);
      onClose();
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not link this Google Doc.', 'brasth-document-sync-for-google-docs');
      setError(message);
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  return {
    attach,
    busy,
    canAttach: Boolean(metadata && metadata.syncCompatibility?.canDownload !== false),
    canChooseElementor,
    changeSourceMode,
    documentInput,
    elementorPreset,
    error,
    inspect,
    layoutPreset,
    metadata,
    outputType,
    selectDocument,
    setDocumentInput,
    setElementorPreset,
    setLayoutPreset,
    setOutputType: changeOutputType,
    uiMode
  };
};
