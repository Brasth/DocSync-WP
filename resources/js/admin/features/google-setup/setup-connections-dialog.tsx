import * as Dialog from '@radix-ui/react-dialog';
import { Fragment, createElement, useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getSettingsConnections, type SettingsConnectionsResponse } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';

const domain = 'brasth-document-sync-for-google-docs';
const labels = {
  connected: __('Connected', domain),
  not_connected: __('Not connected', domain),
  reconnect_required: __('Reconnect required', domain)
};

export const SetupConnectionsDialog = (): JSX.Element => {
  const [open, setOpen] = useState(false);
  const [page, setPage] = useState(1);
  const [data, setData] = useState<SettingsConnectionsResponse | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [retry, setRetry] = useState(0);
  const refresh = useCallback(() => setRetry((value) => value + 1), []);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError('');
    getSettingsConnections(page).then((result) => { if (active) { setData(result); } }).catch((caught) => {
      if (active) { setError(caught instanceof Error ? caught.message : __('Could not load editor connections.', domain)); }
    }).finally(() => { if (active) { setLoading(false); } });
    return () => { active = false; };
  }, [page, retry]);

  return (
    <div className="docsync-wp-editor-connections">
      <div><strong>{__('Other editors', domain)}</strong><p>{data ? sprintf(__('%1$d connected · %2$d not connected · %3$d need to reconnect', domain), data.summary.connected, data.summary.notConnected, data.summary.reconnectRequired) : loading ? __('Loading editor connections…', domain) : __('Editor connections unavailable.', domain)}</p></div>
      <Dialog.Root onOpenChange={(next) => { setOpen(next); if (next) { refresh(); } }} open={open}>
        <Dialog.Trigger asChild><AdminButton type="button" variant="link">{__('View', domain)}</AdminButton></Dialog.Trigger>
        <Dialog.Portal>
          <Dialog.Overlay className="docsync-wp-confirm-dialog__overlay" />
          <Dialog.Content className="docsync-wp-confirm-dialog docsync-wp-connections-dialog">
            <div className="docsync-wp-confirm-dialog__body">
              <Dialog.Title asChild><h2>{__('Editor connections', domain)}</h2></Dialog.Title>
              <Dialog.Description asChild><p>{__('Local connection records for users who can operate Document Sync on this site. No live Google check is made.', domain)}</p></Dialog.Description>
              {loading ? <p role="status">{__('Loading…', domain)}</p> : error ? <div role="alert"><p>{error}</p><AdminButton onClick={refresh}>{__('Retry', domain)}</AdminButton></div> : data ? (
                <><table className="docsync-wp-connections-table"><thead><tr><th scope="col">{__('WordPress user', domain)}</th><th scope="col">{__('Google connection', domain)}</th></tr></thead><tbody>{data.users.map((user) => <tr key={user.userId}><th scope="row">{user.displayName}</th><td>{labels[user.state]}</td></tr>)}</tbody></table>
                  {data.total === 0 ? <p>{__('No eligible editors on this site.', domain)}</p> : null}
                  <div className="docsync-wp-setup-actions"><AdminButton disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>{__('Previous', domain)}</AdminButton><span>{sprintf(__('Page %1$d of %2$d', domain), page, Math.max(1, Math.ceil(data.total / data.perPage)))}</span><AdminButton disabled={page * data.perPage >= data.total} onClick={() => setPage((value) => value + 1)}>{__('Next', domain)}</AdminButton></div></>
              ) : null}
            </div>
            <div className="docsync-wp-confirm-dialog__footer"><Dialog.Close asChild><AdminButton>{__('Close', domain)}</AdminButton></Dialog.Close></div>
          </Dialog.Content>
        </Dialog.Portal>
      </Dialog.Root>
      {error && !open ? <div role="alert"><span>{error} </span><AdminButton onClick={refresh} size="small">{__('Retry', domain)}</AdminButton></div> : null}
    </div>
  );
};
