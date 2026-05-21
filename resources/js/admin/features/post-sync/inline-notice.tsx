import { createElement } from '@wordpress/element';

import type { AdminNoticeState } from '../../shared/ui/admin-notice';

export const InlineNotice = ({ notice }: { notice: AdminNoticeState | null }): JSX.Element | null => {
  if (!notice) {
    return null;
  }

  return <span className={`docsync-wp-inline-notice is-${notice.type}`}>{notice.message}</span>;
};
