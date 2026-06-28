import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { getAdminConfig } from '../../config';
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
};

const trimTrailingSlash = (value: string): string => value.replace(/\/$/, '');

export const AdminShell = ({
  children,
  className = '',
  notice = null,
  status,
  title,
  version
}: Props): JSX.Element => {
  const config = getAdminConfig();
  const markUrl = config.pluginUrl ? `${trimTrailingSlash(config.pluginUrl)}/resources/images/brasth-mark.png` : '';
  const shellClassName = ['docsync-wp-admin-shell', className].filter(Boolean).join(' ');
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
              <p>{__('Brasth Document Sync', 'brasth-document-sync-for-google-docs')}</p>
              <h1>{title}</h1>
              <span>{__('Version', 'brasth-document-sync-for-google-docs')} {version}</span>
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
      </div>
    </main>
  );
};
