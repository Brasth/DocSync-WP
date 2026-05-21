import { Spinner } from '@wordpress/components';
import { createElement } from '@wordpress/element';

type Props = {
  children: string;
  className?: string;
};

export const LoadingState = ({ children, className = 'docsync-wp-drive-browser__state' }: Props): JSX.Element => {
  return (
    <div className={className}>
      <Spinner />
      <span>{children}</span>
    </div>
  );
};
