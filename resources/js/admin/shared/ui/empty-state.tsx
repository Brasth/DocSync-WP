import { createElement } from '@wordpress/element';
import type { ReactNode } from 'react';

type Props = {
  children: ReactNode;
  className?: string;
};

export const EmptyState = ({ children, className = 'docsync-wp-drive-browser__state' }: Props): JSX.Element => {
  return <div className={className}>{children}</div>;
};
