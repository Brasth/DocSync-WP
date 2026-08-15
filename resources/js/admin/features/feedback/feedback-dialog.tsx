import * as Dialog from '@radix-ui/react-dialog';
import { speak } from '@wordpress/a11y';
import { createElement, Fragment, useEffect, useId, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { submitFeedback, type FeedbackType } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';

type Props = {
  onOpenChange?: (open: boolean) => void;
  open: boolean;
};

type FieldErrors = {
  description?: string;
  title?: string;
};

type SuccessState = {
  issueNumber: number;
  issueUrl: string;
};

const MAX_TITLE_LENGTH = 120;
const MAX_DESCRIPTION_LENGTH = 10000;

const typeOptions: Array<{ label: string; value: FeedbackType }> = [
  { label: __('Bug report', 'brasth-document-sync-for-google-docs'), value: 'bug' },
  { label: __('Feature request', 'brasth-document-sync-for-google-docs'), value: 'feature' },
  { label: __('Question', 'brasth-document-sync-for-google-docs'), value: 'question' }
];

const descriptionPlaceholder = (type: FeedbackType): string => {
  if (type === 'feature') {
    return __('What should change, who benefits, and what does success look like?', 'brasth-document-sync-for-google-docs');
  }

  if (type === 'question') {
    return __('What are you trying to do, and what is unclear?', 'brasth-document-sync-for-google-docs');
  }

  return __('What happened, what did you expect, and how can we reproduce it?', 'brasth-document-sync-for-google-docs');
};

const formatCount = (current: number, max: number): string => {
  return sprintf(
    /* translators: 1: current character count, 2: maximum character count */
    __('%1$s / %2$s', 'brasth-document-sync-for-google-docs'),
    String(current),
    String(max)
  );
};

export const FeedbackDialog = ({ onOpenChange, open }: Props): JSX.Element => {
  const baseId = useId();
  const titleInputId = `${baseId}-title`;
  const descriptionInputId = `${baseId}-description`;
  const titleErrorId = `${baseId}-title-error`;
  const descriptionErrorId = `${baseId}-description-error`;
  const formErrorId = `${baseId}-form-error`;
  const titleInputRef = useRef<HTMLInputElement | null>(null);
  const descriptionInputRef = useRef<HTMLTextAreaElement | null>(null);
  const successHeadingRef = useRef<HTMLHeadingElement | null>(null);

  const [type, setType] = useState<FeedbackType>('bug');
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState('');
  const [success, setSuccess] = useState<SuccessState | null>(null);
  const [busy, setBusy] = useState(false);
  const [discardOpen, setDiscardOpen] = useState(false);

  const isDirty = title.trim().length > 0 || description.trim().length > 0;

  useEffect(() => {
    if (success && successHeadingRef.current) {
      successHeadingRef.current.focus();
    }
  }, [success]);

  const reset = () => {
    setType('bug');
    setTitle('');
    setDescription('');
    setFieldErrors({});
    setFormError('');
    setSuccess(null);
    setDiscardOpen(false);
  };

  const closeAndReset = () => {
    reset();
    onOpenChange?.(false);
  };

  const requestClose = (nextOpen: boolean) => {
    if (busy) {
      return;
    }

    if (!nextOpen) {
      if (success) {
        closeAndReset();
        return;
      }

      if (isDirty) {
        setDiscardOpen(true);
        return;
      }

      closeAndReset();
      return;
    }

    onOpenChange?.(nextOpen);
  };

  const validate = (): FieldErrors => {
    const nextErrors: FieldErrors = {};
    const trimmedTitle = title.trim();
    const trimmedDescription = description.trim();

    if (trimmedTitle.length === 0) {
      nextErrors.title = __('Add a title for the report.', 'brasth-document-sync-for-google-docs');
    } else if (trimmedTitle.length > MAX_TITLE_LENGTH) {
      nextErrors.title = __('Titles cannot exceed 120 characters.', 'brasth-document-sync-for-google-docs');
    }

    if (trimmedDescription.length === 0) {
      nextErrors.description = __('Add some details so the report can be investigated.', 'brasth-document-sync-for-google-docs');
    } else if (trimmedDescription.length > MAX_DESCRIPTION_LENGTH) {
      nextErrors.description = __('Details cannot exceed 10,000 characters.', 'brasth-document-sync-for-google-docs');
    }

    return nextErrors;
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const nextErrors = validate();

    if (nextErrors.title || nextErrors.description) {
      setFieldErrors(nextErrors);
      setFormError('');

      if (nextErrors.title) {
        titleInputRef.current?.focus();
      } else {
        descriptionInputRef.current?.focus();
      }

      return;
    }

    setBusy(true);
    setFieldErrors({});
    setFormError('');

    try {
      const response = await submitFeedback({
        description: description.trim(),
        title: title.trim(),
        type
      });
      setSuccess({
        issueNumber: response.issueNumber,
        issueUrl: response.issueUrl
      });
      speak(__('Feedback submitted successfully.', 'brasth-document-sync-for-google-docs'));
    } catch (caught) {
      const message = caught instanceof Error
        ? caught.message
        : __('Could not submit feedback. Please retry later.', 'brasth-document-sync-for-google-docs');
      setFormError(message);
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Fragment>
    <Dialog.Root onOpenChange={requestClose} open={open}>
      <Dialog.Portal>
        <Dialog.Overlay className="docsync-wp-feedback-dialog__overlay" />
        <Dialog.Content
          aria-busy={busy}
          className="docsync-wp-feedback-dialog"
          onInteractOutside={(event) => {
            if (busy || (isDirty && !success)) {
              event.preventDefault();
            }
          }}
          onEscapeKeyDown={(event) => {
            if (busy) {
              event.preventDefault();
            }
          }}
        >
          {success ? (
            <>
              <div className="docsync-wp-feedback-dialog__body">
                <Dialog.Title asChild>
                  <h2 ref={successHeadingRef} tabIndex={-1}>
                    {__('Thanks for the feedback', 'brasth-document-sync-for-google-docs')}
                  </h2>
                </Dialog.Title>
                <Dialog.Description asChild>
                  <p>{__('Your report was created as a public GitHub issue.', 'brasth-document-sync-for-google-docs')}</p>
                </Dialog.Description>
                <div className="docsync-wp-feedback-dialog__success">
                  <strong>
                    {sprintf(
                      /* translators: %s: GitHub issue number */
                      __('Issue #%s created', 'brasth-document-sync-for-google-docs'),
                      String(success.issueNumber)
                    )}
                  </strong>
                  <p>{__('Open the issue to track discussion and updates.', 'brasth-document-sync-for-google-docs')}</p>
                </div>
              </div>
              <div className="docsync-wp-feedback-dialog__footer">
                <AdminButton onClick={() => requestClose(false)}>
                  {__('Done', 'brasth-document-sync-for-google-docs')}
                </AdminButton>
                <a
                  className="button button-primary docsync-wp-button docsync-wp-button--default"
                  href={success.issueUrl}
                  rel="noopener noreferrer"
                  target="_blank"
                >
                  {__('View GitHub issue', 'brasth-document-sync-for-google-docs')}
                  <span className="screen-reader-text">
                    {__('(opens in a new tab)', 'brasth-document-sync-for-google-docs')}
                  </span>
                </a>
              </div>
            </>
          ) : (
            <form noValidate onSubmit={handleSubmit}>
              <div className="docsync-wp-feedback-dialog__body">
                <Dialog.Title asChild>
                  <h2>{__('Create public GitHub issue', 'brasth-document-sync-for-google-docs')}</h2>
                </Dialog.Title>
                <Dialog.Description asChild>
                  <p>{__('Report a bug, request a feature, or ask a question. Maintainers review public issues in Brasth/DocSync-WP.', 'brasth-document-sync-for-google-docs')}</p>
                </Dialog.Description>

                <div className="docsync-wp-feedback-dialog__notice" role="note">
                  <strong>{__('This creates a public GitHub issue', 'brasth-document-sync-for-google-docs')}</strong>
                  {__('Do not include passwords, tokens, private URLs, customer data, or Google document content.', 'brasth-document-sync-for-google-docs')}
                </div>
                <p className="docsync-wp-feedback-dialog__meta">
                  {__('Plugin, WordPress, and PHP version numbers are attached automatically for debugging. Your site URL and WordPress user identity are not sent.', 'brasth-document-sync-for-google-docs')}
                </p>

                <div className="docsync-wp-feedback-dialog__fields">
                  <label htmlFor={`${baseId}-type`}>
                    <span>{__('Type', 'brasth-document-sync-for-google-docs')}</span>
                    <select
                      disabled={busy}
                      id={`${baseId}-type`}
                      onChange={(event) => setType(event.currentTarget.value as FeedbackType)}
                      value={type}
                    >
                      {typeOptions.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                  </label>

                  <label htmlFor={titleInputId}>
                    <span className="docsync-wp-feedback-dialog__label-row">
                      <span>{__('Title', 'brasth-document-sync-for-google-docs')}</span>
                      <span className="docsync-wp-feedback-dialog__counter">
                        {formatCount(title.length, MAX_TITLE_LENGTH)}
                      </span>
                    </span>
                    <input
                      aria-describedby={fieldErrors.title ? titleErrorId : undefined}
                      aria-invalid={fieldErrors.title ? true : undefined}
                      disabled={busy}
                      id={titleInputId}
                      maxLength={MAX_TITLE_LENGTH}
                      onChange={(event) => {
                        setTitle(event.currentTarget.value);
                        if (fieldErrors.title) {
                          setFieldErrors((current) => ({ ...current, title: undefined }));
                        }
                      }}
                      ref={titleInputRef}
                      type="text"
                      value={title}
                    />
                    {fieldErrors.title ? (
                      <p className="docsync-wp-feedback-dialog__field-error" id={titleErrorId}>
                        {fieldErrors.title}
                      </p>
                    ) : null}
                  </label>

                  <label htmlFor={descriptionInputId}>
                    <span className="docsync-wp-feedback-dialog__label-row">
                      <span>{__('Details', 'brasth-document-sync-for-google-docs')}</span>
                      <span className="docsync-wp-feedback-dialog__counter">
                        {formatCount(description.length, MAX_DESCRIPTION_LENGTH)}
                      </span>
                    </span>
                    <textarea
                      aria-describedby={fieldErrors.description ? descriptionErrorId : undefined}
                      aria-invalid={fieldErrors.description ? true : undefined}
                      disabled={busy}
                      id={descriptionInputId}
                      maxLength={MAX_DESCRIPTION_LENGTH}
                      onChange={(event) => {
                        setDescription(event.currentTarget.value);
                        if (fieldErrors.description) {
                          setFieldErrors((current) => ({ ...current, description: undefined }));
                        }
                      }}
                      placeholder={descriptionPlaceholder(type)}
                      ref={descriptionInputRef}
                      rows={7}
                      value={description}
                    />
                    {fieldErrors.description ? (
                      <p className="docsync-wp-feedback-dialog__field-error" id={descriptionErrorId}>
                        {fieldErrors.description}
                      </p>
                    ) : null}
                  </label>
                </div>

                {formError ? (
                  <p className="docsync-wp-feedback-dialog__error" id={formErrorId} role="alert">
                    {formError}
                  </p>
                ) : null}
              </div>

              <div className="docsync-wp-feedback-dialog__footer">
                <AdminButton disabled={busy} onClick={() => requestClose(false)}>
                  {__('Cancel', 'brasth-document-sync-for-google-docs')}
                </AdminButton>
                <AdminButton disabled={busy} type="submit" variant="primary">
                  {busy
                    ? __('Publishing...', 'brasth-document-sync-for-google-docs')
                    : __('Publish public issue', 'brasth-document-sync-for-google-docs')}
                </AdminButton>
              </div>
            </form>
          )}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
    <ConfirmDialog
      cancelLabel={__('Keep editing', 'brasth-document-sync-for-google-docs')}
      confirmLabel={__('Discard draft', 'brasth-document-sync-for-google-docs')}
      description={__('Your title and details will be lost.', 'brasth-document-sync-for-google-docs')}
      open={discardOpen}
      title={__('Discard this feedback draft?', 'brasth-document-sync-for-google-docs')}
      variant="danger"
      onConfirm={closeAndReset}
      onOpenChange={setDiscardOpen}
    />
    </Fragment>
  );
};
