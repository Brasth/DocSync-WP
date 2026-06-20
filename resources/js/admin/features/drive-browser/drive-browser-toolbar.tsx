import { SearchControl, SelectControl } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { SharedDriveSummary } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';

type Props = {
  activeSearch: string;
  busy: boolean;
  driveId: string;
  loading: boolean;
  loadingSharedDrives: boolean;
  searchInput: string;
  sharedDrives: SharedDriveSummary[];
  onClearSearch: () => Promise<void>;
  onDriveChange: (driveId: string) => Promise<void>;
  onRefresh: () => Promise<void>;
  onSearchInputChange: (value: string) => void;
  onSubmitSearch: () => Promise<void>;
};

export const DriveBrowserToolbar = ({
  activeSearch,
  busy,
  driveId,
  loading,
  loadingSharedDrives,
  searchInput,
  sharedDrives,
  onClearSearch,
  onDriveChange,
  onRefresh,
  onSearchInputChange,
  onSubmitSearch
}: Props): JSX.Element => {
  return (
    <form
      className="docsync-wp-drive-browser__toolbar"
      onSubmit={(event) => {
        event.preventDefault();
        onSubmitSearch().catch(() => undefined);
      }}
    >
      <SelectControl
        disabled={busy || loading || loadingSharedDrives}
        label={__('Location', 'brasth-document-sync-for-google-docs')}
        onChange={(value) => onDriveChange(value).catch(() => undefined)}
        options={[
          { label: __('My Drive', 'brasth-document-sync-for-google-docs'), value: '' },
          ...sharedDrives.map((drive) => ({ label: drive.name, value: drive.driveId }))
        ]}
        value={driveId}
      />
      <SearchControl
        label={__('Search this folder', 'brasth-document-sync-for-google-docs')}
        onChange={(value) => onSearchInputChange(value)}
        placeholder={__('Folder or document name', 'brasth-document-sync-for-google-docs')}
        value={searchInput}
      />
      <AdminButton disabled={busy || loading} type="submit" variant="secondary">
        {__('Search', 'brasth-document-sync-for-google-docs')}
      </AdminButton>
      <AdminButton disabled={busy || loading} onClick={onRefresh} variant="secondary">
        {__('Refresh', 'brasth-document-sync-for-google-docs')}
      </AdminButton>
      {activeSearch ? (
        <AdminButton disabled={busy || loading} onClick={onClearSearch} variant="link">
          {__('Clear', 'brasth-document-sync-for-google-docs')}
        </AdminButton>
      ) : null}
    </form>
  );
};
