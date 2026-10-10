import { Fragment, createElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { getAdminConfig } from '../../config';
import { FeedbackDialog } from '../../features/feedback/feedback-dialog';
import { AdminButton } from './admin-button';
import { AdminNotice, type AdminNoticeState } from './admin-notice';

type AdminShellStatus = {
  label: ReactNode;
  value: ReactNode;
  variant?: 'default' | 'ready' | 'attention';
};

type Props = {
  children: ReactNode;
  className?: string;
  notice?: AdminNoticeState | null;
  status?: AdminShellStatus;
  title: ReactNode;
  version: string;
  variant?: 'default' | 'setup';
};

const trimTrailingSlash = (value: string): string => value.replace(/\/$/, '');

export const AdminShell = ({
  children,
  className = '',
  notice = null,
  status,
  title,
  version,
  variant = 'default'
}: Props): JSX.Element => {
  const config = getAdminConfig();
  const [feedbackOpen, setFeedbackOpen] = useState(false);
  const markUrl = config.pluginUrl ? `${trimTrailingSlash(config.pluginUrl)}/resources/images/brasth-mark.png` : '';
  const shellClassName = ['docsync-wp-admin-shell', variant === 'setup' ? 'docsync-wp-admin-shell--setup' : '', className].filter(Boolean).join(' ');
  const statusClassName = [
    'docsync-wp-masthead__status',
    status?.variant ? `docsync-wp-masthead__status--${status.variant}` : ''
  ].filter(Boolean).join(' ');

  return (
    <main className={shellClassName}>
      <div className="docsync-wp-admin-shell__container">
        <header className="docsync-wp-masthead">
          <div className="docsync-wp-masthead__identity">
            {markUrl ? (
              <img
                alt=""
                aria-hidden="true"
                className="docsync-wp-masthead__mark"
                height="44"
                src={markUrl}
                width="44"
              />
            ) : (
              <span aria-hidden="true" className="docsync-wp-masthead__fallback-mark">B</span>
            )}
            <div className="docsync-wp-masthead__copy">
              {variant === 'setup' ? <><h1>{__('Document Sync', 'brasth-document-sync-for-google-docs')}</h1><p>{__('Google Docs → WordPress', 'brasth-document-sync-for-google-docs')}</p></> : <><p>{__('Brasth Document Sync', 'brasth-document-sync-for-google-docs')}</p><h1>{title}</h1><span>{__('Version', 'brasth-document-sync-for-google-docs')} {version}</span></>}
            </div>
          </div>
          {status ? (
            <div className={statusClassName}>
              <strong>{status.value}</strong>
              <span>{status.label}</span>
            </div>
          ) : null}
        </header>
        {variant === 'setup' ? <nav aria-label={__('Document Sync', 'brasth-document-sync-for-google-docs')} className="docsync-wp-setup-tabs">
          <a href="admin.php?page=brasth-document-sync-for-google-docs-sources">{__('Sources', 'brasth-document-sync-for-google-docs')}</a>
          <a href="admin.php?page=brasth-document-sync-for-google-docs-folders">{__('Folders', 'brasth-document-sync-for-google-docs')}</a>
          <a href="admin.php?page=brasth-document-sync-for-google-docs-logs">{__('Activity', 'brasth-document-sync-for-google-docs')}</a>
          <a aria-current="page" href="admin.php?page=brasth-document-sync-for-google-docs">{__('Settings', 'brasth-document-sync-for-google-docs')}</a>
        </nav> : null}

        <AdminNotice className="docsync-wp-admin-shell__notice" notice={notice} />

        <div className="docsync-wp-admin-shell__content">
          {children}
        </div>

        <footer className="docsync-wp-admin-shell__footer">
          <span>{__('Found a problem or have an idea?', 'brasth-document-sync-for-google-docs')}</span>
          <AdminButton onClick={() => setFeedbackOpen(true)} size="small">
            {__('Create issue', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </footer>
      </div>
      <FeedbackDialog onOpenChange={setFeedbackOpen} open={feedbackOpen} />
    </main>
  );
};
