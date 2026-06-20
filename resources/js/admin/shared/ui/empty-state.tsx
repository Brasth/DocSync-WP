import { createElement, Fragment } from '@wordpress/element';
import type { ReactNode } from 'react';

type Props = {
  action?: ReactNode;
  children?: ReactNode;
  className?: string;
  description?: ReactNode;
  title?: ReactNode;
};

export const EmptyState = ({
  action,
  children,
  className = 'docsync-wp-drive-browser__state',
  description,
  title
}: Props): JSX.Element => {
  const stateClassName = title || description || action ? `${className} docsync-wp-empty-state` : className;

  return (
    <div className={stateClassName}>
      {children ?? (
        <>
          {title ? <strong>{title}</strong> : null}
          {description ? <span>{description}</span> : null}
          {action ?? null}
        </>
      )}
    </div>
  );
};
