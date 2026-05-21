import { SearchControl, SelectControl } from '@wordpress/components';
import { createElement } from '@wordpress/element';

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
        label="Location"
        onChange={(value) => onDriveChange(value).catch(() => undefined)}
        options={[
          { label: 'My Drive', value: '' },
          ...sharedDrives.map((drive) => ({ label: drive.name, value: drive.driveId }))
        ]}
        value={driveId}
      />
      <SearchControl
        label="Search this folder"
        onChange={(value) => onSearchInputChange(value)}
        placeholder="Folder or document name"
        value={searchInput}
      />
      <AdminButton disabled={busy || loading} type="submit" variant="secondary">
        Search
      </AdminButton>
      <AdminButton disabled={busy || loading} onClick={onRefresh} variant="secondary">
        Refresh
      </AdminButton>
      {activeSearch ? (
        <AdminButton disabled={busy || loading} onClick={onClearSearch} variant="link">
          Clear
        </AdminButton>
      ) : null}
    </form>
  );
};
