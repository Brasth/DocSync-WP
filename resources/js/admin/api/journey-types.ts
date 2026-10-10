/**
 * Journey 2 wire types. They mirror plans/20261010-journey-2-add-source/contracts.md
 * sections 3 to 7 exactly; keys are camelCase and timestamps are ISO-8601 UTC strings.
 */
import type { DriveItemSummary, GoogleAccount, SourceRecord } from './types';

/* ------------------------------------------------------------------ */
/* Import sessions (contracts section 3)                               */
/* ------------------------------------------------------------------ */

export type ImportFormat = 'docx' | 'pptx' | 'pdf';

export type ImportSessionStatus = 'open' | 'committing' | 'committed' | 'cancelled' | 'expired';

export type ImportFileStatus =
  | 'converting'
  | 'awaitingGoogleWrite'
  | 'needsRender'
  | 'ready'
  | 'failed'
  | 'committing'
  | 'committed'
  | 'skipped';

export type ImportError = { code: string; message: string };

export type PptxOptions = {
  slides: number[];
  includeNotes: boolean;
  addSlideImages: boolean;
  mergeConsecutiveTitles: boolean;
};

export type PdfRenderMode = 'auto' | 'text' | 'image';

export type PdfOptions = {
  pages: number[];
  renderMode: PdfRenderMode;
};

export type ImportFileOptions = {
  title: string;
  target: { postType: string };
  layoutPreset: string;
  docx?: { keepSynced: boolean };
  pptx?: PptxOptions;
  pdf?: PdfOptions;
};

/** PATCH body: any subset of the options; nested format options are replaced as a whole object. */
export type ImportFileOptionsPatch = {
  title?: string;
  target?: { postType: string };
  layoutPreset?: string;
  docx?: { keepSynced: boolean };
  pptx?: PptxOptions;
  pdf?: PdfOptions;
};

export type PptxDetection = {
  hasNotes: boolean;
  suggestedSkips: { slide: number; reason: 'titleOnly' | 'closing' }[];
};

export type Statistics = {
  sections: number;
  blocks: number;
  paragraphs: number;
  headings: number;
  lists: number;
  tables: number;
  images: number;
  words: number;
  characters: number;
  assets: number;
  pendingRenders: number;
  skippedPages: number;
  skippedSlides: number;
};

export type WarningCode =
  | 'unsupportedElement'
  | 'imageSkipped'
  | 'pageRenderedAsImage'
  | 'stylingDropped'
  | 'tableSimplified'
  | 'linkRemoved'
  | 'pdfTableAsText'
  | 'emptySection';

export type Origin = {
  page?: number;
  slide?: number;
  docIndex?: number;
  bounds?: { x: number; y: number; width: number; height: number };
};

export type ImportWarning = {
  number: number;
  code: WarningCode;
  message: string;
  severity: 'info' | 'warning';
  origin?: Origin;
  element?: 'chart' | 'video' | 'animation' | 'wordArt' | 'group';
};

export type PendingRender = {
  assetId: string;
  page: number;
  widthPt: number;
  heightPt: number;
  /** Top-left crop in displayed points. Absent on older pending rows; the renderer then paints the whole page. */
  x?: number;
  y?: number;
  width?: number;
  height?: number;
};

export type ConversionProgress = {
  step: 'uploading' | 'converting' | 'thumbnails' | 'extracting';
  done: number;
  total: number;
};

export type SlideThumbnail = {
  slide: number;
  assetId: string;
  title: string | null;
  warningNumbers: number[];
};

export type ImportFile = {
  fileId: string;
  originalName: string;
  format: ImportFormat;
  byteSize: number;
  sha256: string;
  status: ImportFileStatus;
  error: ImportError | null;
  options: ImportFileOptions;
  detection: PptxDetection | null;
  statistics: Statistics | null;
  warnings: ImportWarning[];
  pendingRenders: PendingRender[];
  previewFingerprint: string | null;
  sourceCount: { pages?: number; slides?: number };
  conversionProgress: ConversionProgress | null;
  slideThumbnails: SlideThumbnail[];
  originalAssetId: string | null;
};

export type ImportCommitFileResult = {
  fileId: string;
  status: 'created' | 'failed' | 'skipped';
  postId: number | null;
  editUrl: string | null;
  postStatus: 'draft' | null;
  provenance: 'syncedWord' | 'oneTime' | null;
  googleFileId: string | null;
  googleDocUrl: string | null;
  sourceStatus: 'linked' | null;
  error: ImportError | null;
};

export type ImportCommitResult = {
  idempotencyKey: string;
  startedAt: string;
  finishedAt: string | null;
  files: ImportCommitFileResult[];
};

export type ImportSession = {
  sessionId: string;
  version: 1;
  status: ImportSessionStatus;
  createdAt: string;
  updatedAt: string;
  expiresAt: string;
  limits: { maxFiles: 20; maxFileBytes: number; remainingFiles: number };
  storageMode: 'outsideWebroot' | 'encrypted';
  googleWrite: { hasDriveFileScope: boolean; importFolderName: 'Imported from WordPress' };
  files: ImportFile[];
  result: ImportCommitResult | null;
};

export type ImportSessionSummary = Pick<ImportSession, 'sessionId' | 'status' | 'createdAt' | 'expiresAt'> & {
  fileCount: number;
};

export type ImportRejectedFile = { originalName: string; code: string; message: string };

export type AddImportFilesResponse = {
  session: ImportSession;
  added: string[];
  rejected: ImportRejectedFile[];
};

export type CancelImportSessionResponse = {
  sessionId: string;
  status: 'cancelled';
  cleanupPending: boolean;
};

export type CommitImportSessionRequest = {
  idempotencyKey: string;
  files: { fileId: string; previewFingerprint: string }[];
};

/* ------------------------------------------------------------------ */
/* Canonical document (contracts section 4)                            */
/* ------------------------------------------------------------------ */

export type Run = {
  text: string;
  bold?: true;
  italic?: true;
  underline?: true;
  strike?: true;
  code?: true;
  link?: string;
};

export type ListItem = { runs: Run[]; children: ListItem[] };

export type TableCell = { runs: Run[]; header: boolean; colSpan: number; rowSpan: number };

export type Block =
  | { type: 'paragraph'; id: string; runs: Run[]; align?: 'left' | 'center' | 'right' | 'justify'; origin: Origin }
  | { type: 'heading'; id: string; level: 1 | 2 | 3 | 4 | 5 | 6; runs: Run[]; origin: Origin }
  | { type: 'list'; id: string; ordered: boolean; items: ListItem[]; origin: Origin }
  | { type: 'table'; id: string; rows: { cells: TableCell[] }[]; origin: Origin }
  | {
      type: 'image';
      id: string;
      assetId: string;
      alt: string;
      caption: string | null;
      origin: Origin;
      fallback?: { warningNumbers: number[] };
    };

export type Section = {
  id: string;
  kind: 'body' | 'slide' | 'page' | 'notes';
  title: string | null;
  origin: Origin;
  blocks: Block[];
};

export type Asset = {
  assetId: string;
  kind: 'embedded' | 'slideThumbnail' | 'pdfPageRender';
  mimeType: 'image/png' | 'image/jpeg' | 'image/gif' | 'image/webp';
  width: number | null;
  height: number | null;
  byteSize: number | null;
  sha256: string | null;
  status: 'ready' | 'pendingRender' | 'rejected';
  origin: Origin;
};

export type CanonicalDocument = {
  version: 1;
  title: string;
  source: { format: ImportFormat; originalName: string; sha256: string; pages?: number; slides?: number };
  sections: Section[];
  assets: Asset[];
  warnings: ImportWarning[];
  statistics: Statistics;
};

export type ImportPreview = {
  fileId: string;
  previewFingerprint: string;
  title: string;
  layoutPreset: string;
  document: CanonicalDocument;
  blockMarkup: string;
  html: string;
  warnings: ImportWarning[];
  statistics: Statistics;
};

export type StoreRenderedAssetResponse = { asset: Asset; file: ImportFile };

/* ------------------------------------------------------------------ */
/* Matching (contracts section 5)                                      */
/* ------------------------------------------------------------------ */

export type MatchLocation = 'myDrive' | 'sharedWithMe' | 'sharedDrive' | 'recent' | 'starred';

export type CreateMatchJobRequest = {
  posts: { postType: string; postIds?: number[] };
  scope: { location: MatchLocation; folderId: string; driveId: string; fileIds: string[] };
};

export type MatchInventoryWarningCode =
  | 'docLimitReached'
  | 'folderLimitReached'
  | 'depthLimitReached'
  | 'incompleteSearch'
  | 'listingFailed'
  | 'fileUnavailable';

export type MatchInventory = {
  complete: boolean;
  docsFound: number;
  foldersVisited: number;
  foldersQueued: number;
  pagesFetched: number;
  warnings: { code: MatchInventoryWarningCode; message: string; folderId: string | null; fileId: string | null }[];
};

export type MatchCandidate = {
  fileId: string;
  name: string;
  webViewLink: string;
  modifiedTime: string;
  kind: 'exactTitle' | 'exactLeadingTokens' | 'approximate';
  score: number;
  linkedPostId: number | null;
};

export type MatchRowState = 'preselected' | 'ambiguous' | 'approximate' | 'none' | 'conflict' | 'created' | 'alreadyLinked';

export type MatchRow = {
  postId: number;
  postTitle: string;
  postType: string;
  editUrl: string;
  state: MatchRowState;
  selectedFileId: string | null;
  matchKind: 'exactTitle' | 'exactLeadingTokens' | 'created' | null;
  preselectBlocked: 'inventoryIncomplete' | null;
  candidates: MatchCandidate[];
};

export type MatchJobStatus = 'queued' | 'listing' | 'running' | 'ready' | 'failed' | 'committed' | 'expired';

export type MatchJob = {
  jobId: string;
  version: 1;
  status: MatchJobStatus;
  createdAt: string;
  expiresAt: string;
  progress: { processed: number; total: number };
  inventory: MatchInventory;
  error: ImportError | null;
  rows: MatchRow[];
};

export type MatchCompare = {
  postId: number;
  fileId: string;
  compareFingerprint: string;
  titleMatch: boolean;
  leadingTokensMatch: boolean;
  score: number;
  post: { title: string; wordCount: number; modifiedAt: string; excerpt: string };
  doc: { name: string; wordCount: number; modifiedTime: string; webViewLink: string; excerpt: string };
  diff: { op: 'equal' | 'delete' | 'insert'; text: string }[];
};

export type MatchCommitPair = { postId: number; fileId: string; compareFingerprint?: string };

export type MatchCommitResponse = {
  jobId: string;
  results: { postId: number; fileId: string; status: 'linked' | 'failed'; error: ImportError | null }[];
};

export type MatchCreateDocResponse = { row: MatchRow };

/* ------------------------------------------------------------------ */
/* Drive and OAuth additions (contracts section 6)                     */
/* ------------------------------------------------------------------ */

export type DriveOwnerFilter = 'any' | 'me' | 'others';
export type DriveLinkedFilter = 'any' | 'linked' | 'unlinked';

export type JourneyDriveItemsQuery = {
  location: MatchLocation;
  folderId?: string;
  driveId?: string;
  search?: string;
  globalSearch?: boolean;
  owner?: DriveOwnerFilter;
  linked?: DriveLinkedFilter;
  pageToken?: string;
  pageSize?: number;
};

export type JourneyDriveItem = DriveItemSummary & {
  ownedByMe?: boolean;
  ownerDisplayName?: string;
  linked?: boolean;
  linkedPostId?: number | null;
};

export type JourneyDriveItemsResponse = {
  items: JourneyDriveItem[];
  nextPageToken?: string;
  incompleteSearch?: boolean;
  folderId: string;
  driveId: string;
};

export type OAuthScopeSet = 'readonly' | 'driveFile';
export type OAuthReturnTo = 'sources' | 'setup';
export type OAuthResumeKind = 'import' | 'matching';

export type OAuthContinuationRequest = {
  scopeSet?: OAuthScopeSet;
  returnTo: OAuthReturnTo;
  resumeKind?: OAuthResumeKind;
  resumeId?: string;
};

export type OAuthContinuationResponse = {
  authUrl: string;
  continuationId?: string;
  expiresAt?: string;
};

export type JourneyGoogleAccount = GoogleAccount & {
  hasDriveFileScope?: boolean;
  driveFileScope?: string;
};

/* ------------------------------------------------------------------ */
/* Sources batch and combined content (contracts section 7)            */
/* ------------------------------------------------------------------ */

export type SourceBatchNewItem = {
  fileId: string;
  target: { mode: 'new'; postType: string; postStatus: 'draft' | 'publish' };
  syncMode: 'background';
  layoutPreset?: string;
  elementorSync?: boolean;
  elementorPreset?: string;
};

export type SourceBatchExistingItem = {
  fileId: string;
  target: { mode: 'existing'; postId: number };
  syncMode: 'attach_only';
  transferOwnership?: boolean;
};

export type SourceBatchItem = SourceBatchNewItem | SourceBatchExistingItem;

export type SourceBatchRequest = { idempotencyKey: string; items: SourceBatchItem[] };

export type SourceBatchResult = {
  index: number;
  fileId: string;
  status: 'queued' | 'linked' | 'failed';
  postId: number | null;
  source: SourceRecord | null;
  error: (ImportError & { status?: number }) | null;
};

export type SourceBatchResponse = { batchId: string; results: SourceBatchResult[] };

export type ContentKind = 'all' | 'google' | 'oneTime';
export type ContentOrderBy = 'modified' | 'title' | 'date';

export type ContentListQuery = {
  page?: number;
  perPage?: number;
  kind?: ContentKind;
  postType?: string;
  search?: string;
  orderBy?: ContentOrderBy;
  order?: 'asc' | 'desc';
  postId?: number;
};

export type ContentProvenance =
  | {
      kind: 'google';
      googleFileId: string;
      googleDocUrl: string;
      source: SourceRecord;
      importedFrom: { format: 'docx'; originalName: string; importedAt: string } | null;
    }
  | {
      kind: 'oneTime';
      format: ImportFormat;
      originalName: string;
      converter: 'googleDocsOneTime' | 'googleSlidesOneTime' | 'localPdf';
      importedAt: string;
      importedByUserId: number;
    };

export type ContentItem = {
  postId: number;
  title: string;
  postType: string;
  postStatus: string;
  editUrl: string;
  viewUrl: string | null;
  modifiedAt: string;
  provenance: ContentProvenance;
};

export type ContentListResponse = {
  items: ContentItem[];
  page: number;
  perPage: number;
  hasMore: boolean;
  truncated: boolean;
};
