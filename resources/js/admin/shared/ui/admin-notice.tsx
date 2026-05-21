import { Notice } from '@wordpress/components';
import { createElement } from '@wordpress/element';

export type AdminNoticeState = {
  type: 'success' | 'error' | 'warning' | 'info';
  message: string;
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
    </Notice>
  );
};
