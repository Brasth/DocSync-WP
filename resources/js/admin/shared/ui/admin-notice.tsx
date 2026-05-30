import { Notice } from '@wordpress/components';
import { createElement } from '@wordpress/element';

import { AdminButton } from './admin-button';

export type AdminNoticeState = {
  type: 'success' | 'error' | 'warning' | 'info';
  message: string;
  actionLabel?: string;
  onAction?: () => void;
};

type Props = {
  className?: string;
  notice: AdminNoticeState | null;
};

export const AdminNotice = ({ className = '', notice }: Props): JSX.Element | null => {
  if (!notice) {
    return null;
  }

  return (
    <Notice className={className} isDismissible={false} status={notice.type}>
      <p>{notice.message}</p>
      {notice.actionLabel && notice.onAction ? (
        <p>
          <AdminButton className="button-small" onClick={notice.onAction} variant="secondary">
            {notice.actionLabel}
          </AdminButton>
        </p>
      ) : null}
    </Notice>
  );
};
