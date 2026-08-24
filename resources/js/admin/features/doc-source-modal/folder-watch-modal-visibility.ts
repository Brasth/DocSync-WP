export const shouldShowDriveBrowser = (uiMode: string, folderConfirmOpen: boolean): boolean => {
  return uiMode === 'browse' && !folderConfirmOpen;
};
