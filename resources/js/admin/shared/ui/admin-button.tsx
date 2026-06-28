import { Button } from '@wordpress/components';
import { createElement, forwardRef } from '@wordpress/element';
import type { ReactNode } from 'react';

type AdminButtonVariant = 'primary' | 'secondary' | 'link' | 'delete';
type AdminButtonSize = 'default' | 'small' | 'icon';

type Props = {
  ariaLabel?: string;
  children: ReactNode;
  className?: string;
  disabled?: boolean;
  size?: AdminButtonSize;
  type?: 'button' | 'submit';
  variant?: AdminButtonVariant;
  onClick?: () => void | Promise<void>;
};

const variantClassName = (variant: AdminButtonVariant): string => {
  if (variant === 'primary') {
    return 'button-primary';
  }

  if (variant === 'link') {
    return 'button-link';
  }

  if (variant === 'delete') {
    return 'button-link-delete';
  }

  return 'button-secondary';
};

export const AdminButton = forwardRef<HTMLButtonElement, Props>(({
  ariaLabel,
  children,
  className = '',
  disabled = false,
  size = 'default',
  type = 'button',
  variant = 'secondary',
  onClick
}, ref): JSX.Element => {
  const classes = [
    'button',
    'docsync-wp-button',
    `docsync-wp-button--${size}`,
    variantClassName(variant),
    className
  ].filter(Boolean).join(' ');

  return (
    <Button aria-label={ariaLabel} className={classes} disabled={disabled} onClick={onClick} ref={ref} type={type}>
      {children}
    </Button>
  );
});
