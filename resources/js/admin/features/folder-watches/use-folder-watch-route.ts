import { useEffect, useState } from '@wordpress/element';

const readWatchId = (): string => {
  return new URLSearchParams(window.location.search).get('watch') || '';
};

export const useFolderWatchRoute = () => {
  const [watchId, setWatchId] = useState(readWatchId);

  useEffect(() => {
    const onPopState = () => {
      setWatchId(readWatchId());
    };

    window.addEventListener('popstate', onPopState);

    return () => window.removeEventListener('popstate', onPopState);
  }, []);

  const openWatch = (id: string) => {
    const url = new URL(window.location.href);
    url.searchParams.set('watch', id);
    window.history.pushState({}, '', url.toString());
    setWatchId(id);
  };

  const closeWatch = () => {
    const url = new URL(window.location.href);
    url.searchParams.delete('watch');
    window.history.pushState({}, '', url.toString());
    setWatchId('');
  };

  return { watchId, openWatch, closeWatch };
};
