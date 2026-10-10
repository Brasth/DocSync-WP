import { createElement, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { isSetupAdminHref } from './setup-admin-links';

const textDomain = 'brasth-document-sync-for-google-docs';

type GuardProps = {
  dirty: boolean;
  open: boolean;
  onLeave: () => void;
  onStay: () => void;
};

export const SetupNavigationGuard = ({ dirty, open, onLeave, onStay }: GuardProps): JSX.Element => {
  useEffect(() => {
    if (!dirty) {
      return undefined;
    }

    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = '';
    };

    window.addEventListener('beforeunload', onBeforeUnload);

    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [dirty]);

  return (
    <ConfirmDialog
      confirmLabel={__('Leave without saving', textDomain)}
      description={__('Unsaved setup changes will be discarded.', textDomain)}
      open={open}
      title={__('Leave this page?', textDomain)}
      variant="danger"
      onConfirm={onLeave}
      onOpenChange={(nextOpen) => {
        if (!nextOpen) {
          onStay();
        }
      }}
    />
  );
};

export const useSetupLeaveGuard = () => {
  const [dirty, setDirty] = useState(false);
  const [open, setOpen] = useState(false);
  const dirtyRef = useRef(false);
  const pendingAction = useRef<(() => void) | null>(null);

  const setDirtyState = useCallback((next: boolean) => {
    if (dirtyRef.current === next) {
      return;
    }

    dirtyRef.current = next;
    setDirty(next);
  }, []);

  const requestLeave = useCallback((action: () => void) => {
    if (!dirtyRef.current) {
      action();
      return;
    }

    pendingAction.current = action;
    setOpen(true);
  }, []);

  const confirmLeave = () => {
    const action = pendingAction.current;
    pendingAction.current = null;
    dirtyRef.current = false;
    setDirty(false);
    setOpen(false);
    window.setTimeout(() => action?.(), 0);
  };

  const stay = () => {
    pendingAction.current = null;
    setOpen(false);
  };

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (!dirtyRef.current || event.defaultPrevented || event.button !== 0) {
        return;
      }

      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }

      const target = event.target;

      if (!(target instanceof Element)) {
        return;
      }

      const anchor = target.closest('a[href]');

      if (!(anchor instanceof HTMLAnchorElement) || anchor.target === '_blank' || anchor.hasAttribute('download')) {
        return;
      }

      const href = anchor.getAttribute('href') ?? '';

      if (!isSetupAdminHref(href)) {
        return;
      }

      event.preventDefault();
      requestLeave(() => window.location.assign(anchor.href));
    };

    document.addEventListener('click', onClick);

    return () => document.removeEventListener('click', onClick);
  }, [requestLeave]);

  return {
    confirmLeave,
    dirty,
    open,
    requestLeave,
    setDirty: setDirtyState,
    stay
  };
};
