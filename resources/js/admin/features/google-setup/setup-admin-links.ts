export const setupAdminLinks = {
  sources: 'admin.php?page=brasth-document-sync-for-google-docs-sources',
  folders: 'admin.php?page=brasth-document-sync-for-google-docs-folders',
  activity: 'admin.php?page=brasth-document-sync-for-google-docs-logs',
  settings: 'admin.php?page=brasth-document-sync-for-google-docs'
} as const;

export const isSetupAdminHref = (href: string): boolean => href.includes('page=brasth-document-sync-for-google-docs');
