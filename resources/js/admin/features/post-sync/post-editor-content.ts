type EditorStore = {
  isEditedPostDirty?: () => boolean;
};

type EditorDispatch = {
  editPost?: (edits: { content: string }) => void;
};

type WordPressData = {
  dispatch?: (store: string) => EditorDispatch | undefined;
  select?: (store: string) => EditorStore | undefined;
};

declare global {
  interface Window {
    wp?: {
      data?: WordPressData;
    };
  }
}

export const getEditorDirtyState = (): boolean | null => {
  try {
    const isDirty = window.wp?.data?.select?.('core/editor')?.isEditedPostDirty?.();
    return typeof isDirty === 'boolean' ? isDirty : null;
  } catch {
    return null;
  }
};

export const applyPostContentToEditor = (content: string): boolean => {
  try {
    const editor = window.wp?.data?.dispatch?.('core/editor');

    if (typeof editor?.editPost !== 'function') {
      return false;
    }

    editor.editPost({ content });
    return true;
  } catch {
    return false;
  }
};
