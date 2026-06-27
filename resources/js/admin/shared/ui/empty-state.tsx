import { createElement, Fragment } from '@wordpress/element';
import type { ReactNode } from 'react';

type EmptyStateVariant = 'setup' | 'sources' | 'logs' | 'drive' | 'sync';

type Props = {
  action?: ReactNode;
  children?: ReactNode;
  className?: string;
  description?: ReactNode;
  title?: ReactNode;
  variant?: EmptyStateVariant;
};

const EmptyStateIllustration = ({ variant }: { variant: EmptyStateVariant }): JSX.Element => {
  const accentClass = `docsync-wp-empty-state__accent docsync-wp-empty-state__accent--${variant}`;

  return (
    <svg aria-hidden="true" className="docsync-wp-empty-state__illustration" focusable="false" viewBox="0 0 96 72">
      <rect className="docsync-wp-empty-state__paper" height="48" rx="6" width="62" x="17" y="12" />
      <path className={accentClass} d="M27 25h34M27 35h42M27 45h24" />
      <circle className={accentClass} cx="70" cy="18" r="8" />
      <path className="docsync-wp-empty-state__shadow" d="M18 62h60" />
    </svg>
  );
};

export const EmptyState = ({
  action,
  children,
  className = 'docsync-wp-drive-browser__state',
  description,
  title,
  variant = 'drive'
}: Props): JSX.Element => {
  const stateClassName = title || description || action
    ? `${className} docsync-wp-empty-state docsync-wp-empty-state--${variant}`
    : className;

  return (
    <div className={stateClassName}>
      {children ?? (
        <>
          <EmptyStateIllustration variant={variant} />
          {title ? <strong>{title}</strong> : null}
          {description ? <span>{description}</span> : null}
          {action ? <div className="docsync-wp-empty-state__action">{action}</div> : null}
        </>
      )}
    </div>
  );
};
