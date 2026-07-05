import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';

type Props = {
  disabled?: boolean;
  onFeatureBlock: () => void;
  onHeroPage: () => void;
  onKeepLegacy: () => void;
};

export const LegacyElementorUpgradeNotice = ({
  disabled = false,
  onFeatureBlock,
  onHeroPage,
  onKeepLegacy
}: Props): JSX.Element => (
  <div className="docsync-wp-legacy-elementor-upgrade" role="status">
    <strong>{__('Legacy Elementor output', 'brasth-document-sync-for-google-docs')}</strong>
    <p>{__('This source keeps the older Elementor conversion until you choose a preset. Presets apply on the next sync.', 'brasth-document-sync-for-google-docs')}</p>
    <div className="docsync-wp-legacy-elementor-upgrade__actions">
      <AdminButton disabled={disabled} onClick={onFeatureBlock} variant="primary">
        {__('Upgrade to Feature Block', 'brasth-document-sync-for-google-docs')}
      </AdminButton>
      <AdminButton disabled={disabled} onClick={onHeroPage}>
        {__('Upgrade to Hero Page', 'brasth-document-sync-for-google-docs')}
      </AdminButton>
      <AdminButton disabled={disabled} onClick={onKeepLegacy}>
        {__('Keep legacy', 'brasth-document-sync-for-google-docs')}
      </AdminButton>
    </div>
  </div>
);
