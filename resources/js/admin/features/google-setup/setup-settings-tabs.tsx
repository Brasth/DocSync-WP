import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const domain = 'brasth-document-sync-for-google-docs';

export type SettingsTab = 'general' | 'health';
type Props = {
  tab: SettingsTab;
  onSelect: (tab: SettingsTab) => void;
};

export const SetupSettingsTabs = ({ tab, onSelect }: Props): JSX.Element => (
  <nav aria-label={__('Settings sections', domain)} className="docsync-wp-settings-tabs">
    <button aria-current={tab === 'general' ? 'page' : undefined} onClick={() => onSelect('general')} type="button">{__('General', domain)}</button>
    <button aria-current={tab === 'health' ? 'page' : undefined} onClick={() => onSelect('health')} type="button">{__('Sync health', domain)}</button>
    <button disabled type="button">{__('Notifications · later', domain)}</button>
  </nav>
);
