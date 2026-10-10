/**
 * Crop geometry for PDF page renders.
 *
 * Bounds are top-left points on the page as displayed, after the page's own
 * 90-degree rotation. PDF.js `offsetX` / `offsetY` are canvas pixels added
 * after that rotation, so a crop shifts by `-origin * pixelsPerPoint` on every
 * rotation. Callers size one canvas to the crop; this module does not rasterize.
 */

export type PdfCropBounds = {
  x: number;
  y: number;
  width: number;
  height: number;
};

export type CropRenderPlan = {
  /** Clockwise displayed rotation accepted for this plan: 0, 90, 180, or 270. */
  rotation: number;
  /** PDF.js `getViewport({ scale })` value. Already divided by `userUnit`. */
  pdfJsScale: number;
  /** Canvas pixels per displayed PDF point. */
  pixelsPerPoint: number;
  offsetX: number;
  offsetY: number;
  canvasWidth: number;
  canvasHeight: number;
  crop: PdfCropBounds;
};

/** Converter page cap (`PdfConverter::pageSize`). Larger wire values are rejected. */
const MAX_POINT = 20000;

const finite = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value);

const withinPageLimit = (value: number): boolean => value <= MAX_POINT;

/**
 * Normalize a PDF page rotation. Values such as -90 become 270.
 * Anything that is not a multiple of 90 is rejected, matching PDF.js.
 */
export const normalizePageRotation = (rotation: number): number => {
  if (!finite(rotation)) {
    throw new RangeError('PDF rotation must be a finite multiple of 90 degrees.');
  }

  const turns = ((Math.round(rotation) % 360) + 360) % 360;

  if (turns !== 0 && turns !== 90 && turns !== 180 && turns !== 270) {
    throw new RangeError('PDF rotation must be a multiple of 90 degrees.');
  }

  return turns;
};

/**
 * Crop rectangle from a pending-render row.
 *
 * Returns null when x/y/width/height are absent or disagree with the
 * widthPt/heightPt pair the server uses for the PNG aspect check. Null means
 * the caller renders the whole displayed page.
 */
export const cropFromPending = (pending: {
  x?: number;
  y?: number;
  width?: number;
  height?: number;
  widthPt: number;
  heightPt: number;
}): PdfCropBounds | null => {
  const { x, y, width, height, widthPt, heightPt } = pending;

  if (!finite(x) || !finite(y) || !finite(width) || !finite(height) || !finite(widthPt) || !finite(heightPt)) {
    return null;
  }

  if (x < 0 || y < 0 || width <= 0 || height <= 0 || widthPt <= 0 || heightPt <= 0) {
    return null;
  }

  if (!withinPageLimit(x) || !withinPageLimit(y) || !withinPageLimit(width) || !withinPageLimit(height)) {
    return null;
  }

  if (Math.abs(width - widthPt) > 0.05 || Math.abs(height - heightPt) > 0.05) {
    return null;
  }

  return { x, y, width, height };
};

const assertCrop = (crop: PdfCropBounds): void => {
  if (!finite(crop.x) || !finite(crop.y) || !finite(crop.width) || !finite(crop.height)) {
    throw new RangeError('Crop bounds must be finite numbers.');
  }

  if (crop.x < 0 || crop.y < 0 || crop.width <= 0 || crop.height <= 0) {
    throw new RangeError('Crop bounds must sit on the displayed page and have a positive size.');
  }

  if (!withinPageLimit(crop.x) || !withinPageLimit(crop.y) || !withinPageLimit(crop.width) || !withinPageLimit(crop.height)) {
    throw new RangeError('Crop bounds exceed the supported page size.');
  }
};

/**
 * Viewport scale, pixel offset, and crop canvas size.
 *
 * `pageWidthPt` and `pageHeightPt` are the displayed page (PDF.js viewport at
 * scale 1, divided by userUnit), not the unrotated media box. A null crop is
 * that whole page, with a zero offset.
 */
export const planCropRender = (input: {
  pageWidthPt: number;
  pageHeightPt: number;
  rotation: number;
  userUnit?: number;
  crop: PdfCropBounds | null;
  widthPx: number;
}): CropRenderPlan => {
  const rotation = normalizePageRotation(input.rotation);
  const userUnit = input.userUnit ?? 1;

  if (!finite(input.pageWidthPt) || !finite(input.pageHeightPt) || input.pageWidthPt <= 0 || input.pageHeightPt <= 0) {
    throw new RangeError('Displayed page size must be positive.');
  }

  if (!finite(userUnit) || userUnit <= 0) {
    throw new RangeError('PDF userUnit must be positive.');
  }

  if (!finite(input.widthPx) || input.widthPx <= 0) {
    throw new RangeError('Render width must be positive.');
  }

  const crop = input.crop ?? { x: 0, y: 0, width: input.pageWidthPt, height: input.pageHeightPt };

  assertCrop(crop);

  const pixelsPerPoint = input.widthPx / crop.width;
  const canvasWidth = Math.round(crop.width * pixelsPerPoint);
  const canvasHeight = Math.max(1, Math.round(crop.height * pixelsPerPoint));
  const offset = (origin: number): number => (origin === 0 ? 0 : -origin * pixelsPerPoint);

  return {
    rotation,
    pdfJsScale: pixelsPerPoint / userUnit,
    pixelsPerPoint,
    offsetX: offset(crop.x),
    offsetY: offset(crop.y),
    canvasWidth,
    canvasHeight,
    crop
  };
};

/** Map a displayed top-left point into the crop canvas. */
export const displayedPointToCanvas = (plan: CropRenderPlan, xPt: number, yPt: number): { x: number; y: number } => ({
  x: xPt * plan.pixelsPerPoint + plan.offsetX,
  y: yPt * plan.pixelsPerPoint + plan.offsetY
});
