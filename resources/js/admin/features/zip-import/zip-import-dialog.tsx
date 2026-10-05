import * as Dialog from '@radix-ui/react-dialog';
import { speak } from '@wordpress/a11y';
import { createElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { importZip, type ZipImportResult } from '../../api';
import type { AvailableLayoutPreset, AvailablePostType } from '../../config';
import { AdminButton } from '../../shared/ui/admin-button';

type Props = {
  postTypes: AvailablePostType[];
  layoutPresets: AvailableLayoutPreset[];
  /** Offer "connect Google" after a successful import. */
  connectHref?: string;
  label?: string;
};

export const ZipImportButton = ({ postTypes, layoutPresets, connectHref, label }: Props): JSX.Element | null => {
  const [open, setOpen] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [postType, setPostType] = useState(postTypes[0]?.name ?? 'post');
  const [preset, setPreset] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState<ZipImportResult | null>(null);

  if (postTypes.length === 0) {
    return null;
  }

  const reset = () => {
    setFile(null);
    setError('');
    setResult(null);
  };

  const submit = async () => {
    if (!file) {
      setError(__('Choose a .zip file to import.', 'brasth-document-sync-for-google-docs'));
      return;
    }

    setBusy(true);
    setError('');

    try {
      const imported = await importZip(file, postType, preset);

      setResult(imported);
      speak(__('Import complete. Draft created.', 'brasth-document-sync-for-google-docs'), 'polite');
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Import failed.', 'brasth-document-sync-for-google-docs');

      setError(message);
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="docsync-wp-zip-import">
      <AdminButton onClick={() => { reset(); setOpen(true); }}>
        {label ?? __('Try it without Google setup', 'brasth-document-sync-for-google-docs')}
      </AdminButton>
      <Dialog.Root open={open} onOpenChange={(next) => { if (!busy) { setOpen(next); } }}>
        <Dialog.Portal>
          <Dialog.Overlay className="docsync-wp-confirm-dialog__overlay" />
          <Dialog.Content aria-busy={busy} className="docsync-wp-confirm-dialog docsync-wp-zip-import__dialog">
            <div className="docsync-wp-confirm-dialog__body">
              <Dialog.Title asChild>
                <h2>{__('Import a downloaded Google Doc', 'brasth-document-sync-for-google-docs')}</h2>
              </Dialog.Title>
              <Dialog.Description asChild>
                <p>
                  {__('In Google Docs choose File > Download > Web Page (.html, zipped), then upload the .zip here. This is a one-time import: the draft is not linked to Google.', 'brasth-document-sync-for-google-docs')}
                </p>
              </Dialog.Description>

              {result ? (
                <div className="docsync-wp-confirm-dialog__extra" role="status">
                  <p><strong>{__('Draft created:', 'brasth-document-sync-for-google-docs')}</strong> {result.title}</p>
                  {connectHref ? (
                    <p>
                      {__('Want it to stay in sync with the Doc?', 'brasth-document-sync-for-google-docs')}{' '}
                      <a href={connectHref}>{__('Connect Google', 'brasth-document-sync-for-google-docs')}</a>
                    </p>
                  ) : null}
                </div>
              ) : (
                <div className="docsync-wp-confirm-dialog__extra">
                  <label className="docsync-wp-field docsync-wp-field--compact">
                    <span>{__('Google Doc ZIP file', 'brasth-document-sync-for-google-docs')}</span>
                    <input
                      accept=".zip,application/zip"
                      disabled={busy}
                      onChange={(event) => setFile(event.currentTarget.files?.[0] ?? null)}
                      type="file"
                    />
                  </label>
                  {postTypes.length > 1 ? (
                    <label className="docsync-wp-field docsync-wp-field--compact">
                      <span>{__('Create as', 'brasth-document-sync-for-google-docs')}</span>
                      <select disabled={busy} onChange={(event) => setPostType(event.currentTarget.value)} value={postType}>
                        {postTypes.map((item) => <option key={item.name} value={item.name}>{item.label}</option>)}
                      </select>
                    </label>
                  ) : null}
                  <label className="docsync-wp-field docsync-wp-field--compact">
                    <span>{__('Layout', 'brasth-document-sync-for-google-docs')}</span>
                    <select disabled={busy} onChange={(event) => setPreset(event.currentTarget.value)} value={preset}>
                      <option value="">{__('Site default', 'brasth-document-sync-for-google-docs')}</option>
                      {layoutPresets.map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}
                    </select>
                  </label>
                  {error ? <p className="docsync-wp-list-error" role="alert">{error}</p> : null}
                </div>
              )}
            </div>
            <div className="docsync-wp-confirm-dialog__footer">
              <Dialog.Close asChild>
                <AdminButton disabled={busy}>{result ? __('Done', 'brasth-document-sync-for-google-docs') : __('Cancel', 'brasth-document-sync-for-google-docs')}</AdminButton>
              </Dialog.Close>
              {result ? (
                <a className="button button-primary docsync-wp-button docsync-wp-button--default" href={result.editUrl}>
                  {__('Open draft', 'brasth-document-sync-for-google-docs')}
                </a>
              ) : (
                <AdminButton disabled={busy || !file} onClick={submit} variant="primary">
                  {busy ? __('Importing...', 'brasth-document-sync-for-google-docs') : __('Import', 'brasth-document-sync-for-google-docs')}
                </AdminButton>
              )}
            </div>
          </Dialog.Content>
        </Dialog.Portal>
      </Dialog.Root>
    </div>
  );
};
