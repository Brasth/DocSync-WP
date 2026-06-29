import { Spinner } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import type { ReactNode } from 'react';

import { SkeletonText } from './skeleton';

type Props = {
  children: ReactNode;
  className?: string;
  variant?: 'spinner' | 'skeleton';
};

export const LoadingState = ({ children, className = 'docsync-wp-drive-browser__state', variant = 'spinner' }: Props): JSX.Element => {
  const stateClassName = variant === 'skeleton' ? `${className} docsync-wp-loading-state--skeleton` : className;

  return (
    <div aria-busy="true" className={stateClassName} role="status">
      {variant === 'spinner' ? <Spinner /> : null}
      <span className="docsync-wp-loading-state__label">{children}</span>
      {variant === 'skeleton' ? (
        <span className="docsync-wp-loading-state__lines" aria-hidden="true">
          <SkeletonText width="76%" />
          <SkeletonText width="54%" />
        </span>
      ) : null}
    </div>
  );
};
