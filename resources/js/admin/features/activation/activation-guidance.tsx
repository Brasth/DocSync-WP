import { createElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { GoogleAccount, WorkspaceResponse } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { StatusPill } from '../../shared/ui/status-pill';
import { adviseActivation } from './activation-advisor';

type Props = {
  account: GoogleAccount;
  busy: boolean;
  onConnect: () => Promise<void>;
  onCreateSource: () => void;
  setupUrl: string;
  workspace: WorkspaceResponse;
};

const sourcesUrl = 'admin.php?page=brasth-document-sync-for-google-docs-sources';

export const ActivationGuidance = ({ account, busy, onConnect, onCreateSource, setupUrl, workspace }: Props): JSX.Element => {
  const advisor = adviseActivation({ workspace, account });
  const copy = {
    site_needs_admin: {
      title: __('Site connection needs an administrator', 'brasth-document-sync-for-google-docs'),
      description: workspace.canManageSettings
        ? __('Save this site\'s Google OAuth web client before anyone connects an account.', 'brasth-document-sync-for-google-docs')
        : __('The site connection is not ready. Ask a WordPress administrator to finish Brasth Document Sync Setup.', 'brasth-document-sync-for-google-docs'),
      status: 'warning'
    },
    account_disconnected: {
      title: __('Connect your Google account', 'brasth-document-sync-for-google-docs'),
      description: __('Authorize Drive read-only access for Docs this WordPress user can read.', 'brasth-document-sync-for-google-docs'),
      status: 'warning'
    },
    scope_outdated: {
      title: __('Reconnect your Google account', 'brasth-document-sync-for-google-docs'),
      description: __('Your existing connection does not include the Drive read-only scope required for browsing and syncing.', 'brasth-document-sync-for-google-docs'),
      status: 'warning'
    },
    ready_for_source: {
      title: workspace.creatablePostTypes.length > 0
        ? __('Create your first publishing source', 'brasth-document-sync-for-google-docs')
        : __('No available WordPress target', 'brasth-document-sync-for-google-docs'),
      description: workspace.creatablePostTypes.length > 0
        ? __('Choose an accessible Google Doc. Brasth will create and safely sync a WordPress draft in the background.', 'brasth-document-sync-for-google-docs')
        : __('Ask an administrator for permission to create an enabled post type before adding a source.', 'brasth-document-sync-for-google-docs'),
      status: 'ready'
    },
    syncing: {
      title: __('First source is syncing', 'brasth-document-sync-for-google-docs'),
      description: __('You can leave this page. The source remains safe while WordPress processes the background sync.', 'brasth-document-sync-for-google-docs'),
      status: 'syncing'
    },
    activated: {
      title: __('Publishing workspace active', 'brasth-document-sync-for-google-docs'),
      description: __('At least one accessible source has completed successfully. Sources is now your daily workspace.', 'brasth-document-sync-for-google-docs'),
      status: 'synced'
    },
    needs_attention: {
      title: sprintf(
        _n('%d source needs attention', '%d sources need attention', workspace.sourceSummary.attention, 'brasth-document-sync-for-google-docs'),
        workspace.sourceSummary.attention
      ),
      description: __('Review the affected sources below. Failed syncs do not overwrite the existing WordPress content.', 'brasth-document-sync-for-google-docs'),
      status: 'error'
    }
  }[advisor.state];

  const action = (() => {
    switch (advisor.action) {
      case 'manage_site_connection':
        return <a className="button button-primary" href={setupUrl}>{__('Open Setup', 'brasth-document-sync-for-google-docs')}</a>;
      case 'contact_admin':
      case 'wait_for_sync':
        return null;
      case 'connect_google':
        return <AdminButton disabled={busy} onClick={onConnect} variant="primary">{__('Connect Google', 'brasth-document-sync-for-google-docs')}</AdminButton>;
      case 'reconnect_google':
        return <AdminButton disabled={busy} onClick={onConnect} variant="primary">{__('Reconnect Google', 'brasth-document-sync-for-google-docs')}</AdminButton>;
      case 'create_source':
        return <AdminButton disabled={busy || workspace.creatablePostTypes.length === 0} onClick={onCreateSource} variant="primary">{__('Choose Google Doc', 'brasth-document-sync-for-google-docs')}</AdminButton>;
      case 'view_sources':
      case 'review_attention':
        return <a className="button button-primary" href={sourcesUrl}>{advisor.action === 'review_attention' ? __('Review sources', 'brasth-document-sync-for-google-docs') : __('View Sources', 'brasth-document-sync-for-google-docs')}</a>;
    }
  })();

  return (
    <section aria-labelledby="docsync-wp-activation-title" className={`docsync-wp-activation-guidance is-${advisor.state}`}>
      <div className="docsync-wp-activation-guidance__copy">
        <span className="docsync-wp-kicker">{__('Next responsibility', 'brasth-document-sync-for-google-docs')}</span>
        <h2 id="docsync-wp-activation-title">{copy.title}</h2>
        <p>{copy.description}</p>
        <small className="docsync-wp-activation-guidance__evidence">
          {advisor.stage === 'site'
            ? __('Site connection', 'brasth-document-sync-for-google-docs')
            : advisor.stage === 'account'
              ? __('Your Google account', 'brasth-document-sync-for-google-docs')
              : __('Publishing source', 'brasth-document-sync-for-google-docs')}
        </small>
      </div>
      <div className="docsync-wp-activation-guidance__action">
        <StatusPill status={copy.status} />
        {action}
      </div>
    </section>
  );
};
