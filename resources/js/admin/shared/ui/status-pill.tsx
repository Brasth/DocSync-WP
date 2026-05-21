import { createElement } from '@wordpress/element';

type Props = {
  status: string;
};

export const StatusPill = ({ status }: Props): JSX.Element => {
  return <span className={`docsync-wp-pill is-${status}`}>{status}</span>;
};
