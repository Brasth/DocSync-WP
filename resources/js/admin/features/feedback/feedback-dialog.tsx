import * as Dialog from '@radix-ui/react-dialog';
import { speak } from '@wordpress/a11y';
import { createElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { submitFeedback, type FeedbackType } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';

type Props = {
  onOpenChange?: (open: boolean) => void;
  open: boolean;
};

const typeOptions: Array<{ label: string; value: FeedbackType }> = [
  { label: __('Bug report', 'brasth-document-sync-for-google-docs'), value: 'bug' },
  { label: __('Feature request', 'brasth-document-sync-for-google-docs'), value: 'feature' },
  { label: __('Question', 'brasth-document-sync-for-google-docs'), value: 'question' }
];

export const FeedbackDialog = ({ onOpenChange, open }: Props): JSX.Element => {
  const [type, setType] = useState<FeedbackType>('bug');
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [error, setError] = useState('');
  const [issueUrl, setIssueUrl] = useState('');
  const [busy, setBusy] = useState(false);

  const reset = () => {
    setType('bug');
    setTitle('');
    setDescription('');
    setError('');
    setIssueUrl('');
  };

  const handleOpenChange = (nextOpen: boolean) => {
    if (busy) {
      return;
    }

    if (!nextOpen) {
      reset();
    }

    onOpenChange?.(nextOpen);
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const trimmedTitle = title.trim();
    const trimmedDescription = description.trim();

    if (trimmedTitle.length === 0) {
      setError(__('Add a title for the report.', 'brasth-document-sync-for-google-docs'));
      return;
    }

    if (trimmedDescription.length === 0) {
      setError(__('Add some details so the report can be investigated.', 'brasth-document-sync-for-google-docs'));
      return;
    }

    setBusy(true);
    setError('');

    try {
      const response = await submitFeedback({
        description: trimmedDescription,
        title: trimmedTitle,
        type
      });
      setIssueUrl(response.issueUrl);
      speak(__('Feedback submitted successfully.', 'brasth-document-sync-for-google-docs'));
    } catch (caught) {
      const message = caught instanceof Error
        ? caught.message
        : __('Could not submit feedback. Please retry later.', 'brasth-document-sync-for-google-docs');
      setError(message);
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog.Root onOpenChange={handleOpenChange} open={open}>
      <Dialog.Portal>
        <Dialog.Overlay className="docsync-wp-feedback-dialog__overlay" />
        <Dialog.Content aria-busy={busy} className="docsync-wp-feedback-dialog">
          {issueUrl ? (
            <div className="docsync-wp-feedback-dialog__body">
              <Dialog.Title asChild>
                <h2>{__('Thanks for the feedback', 'brasth-document-sync-for-google-docs')}</h2>
              </Dialog.Title>
              <Dialog.Description asChild>
                <p>{__('Your report was created as a public GitHub issue.', 'brasth-document-sync-for-google-docs')}</p>
              </Dialog.Description>
              <p className="docsync-wp-feedback-dialog__success">
                <a href={issueUrl} rel="noopener noreferrer" target="_blank">
                  {__('Open the GitHub issue', 'brasth-document-sync-for-google-docs')}
                </a>
              </p>
            </div>
          ) : (
            <form onSubmit={handleSubmit}>
              <div className="docsync-wp-feedback-dialog__body">
                <Dialog.Title asChild>
                  <h2>{__('Send feedback', 'brasth-document-sync-for-google-docs')}</h2>
                </Dialog.Title>
                <Dialog.Description asChild>
                  <p>{__('This creates a public issue in Brasth/DocSync-WP. Do not include passwords, tokens, private URLs, or customer data.', 'brasth-document-sync-for-google-docs')}</p>
                </Dialog.Description>
                <div className="docsync-wp-feedback-dialog__fields">
                  <label>
                    <span>{__('Type', 'brasth-document-sync-for-google-docs')}</span>
                    <select disabled={busy} onChange={(event) => setType(event.currentTarget.value as FeedbackType)} value={type}>
                      {typeOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </select>
                  </label>
                  <label>
                    <span>{__('Title', 'brasth-document-sync-for-google-docs')}</span>
                    <input
                      disabled={busy}
                      maxLength={120}
                      onChange={(event) => setTitle(event.currentTarget.value)}
                      required
                      type="text"
                      value={title}
                    />
                  </label>
                  <label>
                    <span>{__('Details', 'brasth-document-sync-for-google-docs')}</span>
                    <textarea
                      disabled={busy}
                      maxLength={10000}
                      onChange={(event) => setDescription(event.currentTarget.value)}
                      placeholder={__('What happened, what did you expect, and how can we reproduce it?', 'brasth-document-sync-for-google-docs')}
                      required
                      rows={7}
                      value={description}
                    />
                  </label>
                </div>
                {error ? <p aria-live="assertive" className="docsync-wp-feedback-dialog__error" role="alert">{error}</p> : null}
              </div>
              <div className="docsync-wp-feedback-dialog__footer">
                <AdminButton disabled={busy} onClick={() => handleOpenChange(false)}>{__('Cancel', 'brasth-document-sync-for-google-docs')}</AdminButton>
                <AdminButton disabled={busy} type="submit" variant="primary">
                  {busy ? __('Submitting...', 'brasth-document-sync-for-google-docs') : __('Submit issue', 'brasth-document-sync-for-google-docs')}
                </AdminButton>
              </div>
            </form>
          )}
          {issueUrl ? (
            <div className="docsync-wp-feedback-dialog__footer">
              <AdminButton disabled={busy} onClick={() => handleOpenChange(false)} variant="primary">{__('Done', 'brasth-document-sync-for-google-docs')}</AdminButton>
            </div>
          ) : null}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
};
