import { createElement, useState } from '@wordpress/element';
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
  density?: 'default' | 'compact';
  notice?: AdminNoticeState | null;
  status?: AdminShellStatus;
  title: ReactNode;
  version: string;
};

const trimTrailingSlash = (value: string): string => value.replace(/\/$/, '');

export const AdminShell = ({
  children,
  className = '',
  density = 'default',
  notice = null,
  status,
  title,
  version
}: Props): JSX.Element => {
  const config = getAdminConfig();
  const [feedbackOpen, setFeedbackOpen] = useState(false);
  const markUrl = config.pluginUrl ? `${trimTrailingSlash(config.pluginUrl)}/resources/images/brasth-mark.png` : '';
  const shellClassName = [
    'docsync-wp-admin-shell',
    density === 'compact' ? 'docsync-wp-admin-shell--compact' : '',
    className
  ].filter(Boolean).join(' ');
  const statusClassName = [
    'docsync-wp-masthead__status',
    status?.variant ? `docsync-wp-masthead__status--${status.variant}` : ''
  ].filter(Boolean).join(' ');

  return (
    <main className={shellClassName}>
      <div className="docsync-wp-admin-shell__container">
        <header className="docsync-wp-masthead">
          <div className="docsync-wp-masthead__identity">
            {density === 'compact' ? null : markUrl ? (
              <img
                alt=""
                aria-hidden="true"
                className="docsync-wp-masthead__mark"
                height="36"
                src={markUrl}
                width="36"
              />
            ) : (
              <span aria-hidden="true" className="docsync-wp-masthead__fallback-mark">B</span>
            )}
            <div className="docsync-wp-masthead__copy">
              {density === 'compact' ? null : (
                <p>{__('Brasth Document Sync', 'brasth-document-sync-for-google-docs')}</p>
              )}
              <h1>{title}</h1>
              {density === 'compact' ? null : (
                <span>{__('Version', 'brasth-document-sync-for-google-docs')} {version}</span>
              )}
            </div>
          </div>
          {status ? (
            <div className={statusClassName}>
              <strong>{status.value}</strong>
              <span>{status.label}</span>
            </div>
          ) : null}
        </header>

        <AdminNotice className="docsync-wp-admin-shell__notice" notice={notice} />

        <div className="docsync-wp-admin-shell__content">
          {children}
        </div>

        <footer className="docsync-wp-admin-shell__footer">
          <AdminButton onClick={() => setFeedbackOpen(true)} size="small" variant="link">
            {__('Create issue', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </footer>
      </div>
      <FeedbackDialog onOpenChange={setFeedbackOpen} open={feedbackOpen} />
    </main>
  );
};
