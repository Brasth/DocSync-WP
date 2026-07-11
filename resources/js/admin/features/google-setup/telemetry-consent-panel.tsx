import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';

const privacyPolicyUrl = 'https://docsyncwp.com/privacy-policy';

type Props = {
  busy: boolean;
  onAccept: () => Promise<boolean>;
  onDismiss: () => Promise<boolean>;
};

export const TelemetryConsentPanel = ({
  busy,
  onAccept,
  onDismiss
}: Props): JSX.Element => (
  <section aria-labelledby="docsync-wp-telemetry-consent-title" className="docsync-wp-telemetry-consent">
    <span aria-hidden="true" className="dashicons dashicons-chart-area docsync-wp-telemetry-consent__icon" />
    <div className="docsync-wp-telemetry-consent__body">
      <h2 id="docsync-wp-telemetry-consent-title">{__('Help improve Brasth Document Sync', 'brasth-document-sync-for-google-docs')}</h2>
      <p>
        {__('Share one anonymous weekly check-in so we can understand active installs and version compatibility. No Google data, site URL, user email, document IDs, or content is sent.', 'brasth-document-sync-for-google-docs')}
      </p>
      <div className="docsync-wp-telemetry-consent__actions">
        <AdminButton disabled={busy} onClick={async () => { await onAccept(); }} size="small" variant="primary">
          {__('Share anonymous diagnostics', 'brasth-document-sync-for-google-docs')}
        </AdminButton>
        <AdminButton disabled={busy} onClick={async () => { await onDismiss(); }} size="small" variant="link">
          {__('No thanks', 'brasth-document-sync-for-google-docs')}
        </AdminButton>
        <a href={privacyPolicyUrl} rel="noreferrer" target="_blank">
          {__('Privacy policy', 'brasth-document-sync-for-google-docs')}
        </a>
      </div>
    </div>
  </section>
);
