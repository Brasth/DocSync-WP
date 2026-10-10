/**
 * Network-lazy PDF renderer bundle (contracts section 11.1).
 *
 * This module is the only code that imports `pdfjs-dist`. It is built by the dedicated
 * `pdf-renderer` Vite mode and injected at runtime by `loadPdfRenderer` only when a PDF
 * thumbnail, page picker, or page render is about to be shown. Main-bundle modules use
 * `import type` from this file and never import it as a value.
 */
import { getDocument, GlobalWorkerOptions, type PDFDocumentProxy, type PDFPageProxy } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

import { planCropRender, type PdfCropBounds } from './pdf-render-geometry';

export type PdfRendererDocument = {
  pageCount: number;
  getPageSize(page: number): Promise<{ widthPt: number; heightPt: number }>;
  renderThumbnail(page: number, canvas: HTMLCanvasElement, maxWidthPx: number): Promise<void>;
  renderPagePng(page: number, widthPx: number, crop?: PdfCropBounds | null): Promise<Blob>;
  destroy(): Promise<void>;
};

export type DocSyncWPPdfRenderer = {
  version: 1;
  open(source: { url: string }): Promise<PdfRendererDocument>;
};

declare global {
  interface Window {
    DocSyncWPPdfRenderer?: DocSyncWPPdfRenderer;
  }
}

const MIN_RENDER_WIDTH = 200;
const MAX_RENDER_WIDTH = 2400;

/** Plugin root URL with a trailing slash; every PDF.js asset loads from this site, never a CDN. */
const pluginBaseUrl = (): string => (window.DocSyncWPAdmin?.pluginUrl ?? '').replace(/\/?$/, '/');

/** Resolve the hashed local worker against the plugin's `build/` directory. */
const resolveWorkerSrc = (): string => {
  const assetIndex = workerUrl.indexOf('assets/');
  const relative = assetIndex >= 0 ? workerUrl.slice(assetIndex) : workerUrl.replace(/^\.?\//, '');

  return `${pluginBaseUrl()}build/${relative}`;
};

/** pdfjs-dist 5.6 does not re-export `RenderParameters` from its entry point; derive it from `render`. */
type RenderParameters = Parameters<PDFPageProxy['render']>[0];

/** Local CMap, standard font, and wasm directories copied into `build/assets/pdf/` at build time. */
const pdfAssetUrl = (directory: 'cmaps' | 'standard_fonts' | 'wasm'): string =>
  `${pluginBaseUrl()}build/assets/pdf/${directory}/`;

const drawPage = async (
  page: PDFPageProxy,
  canvas: HTMLCanvasElement,
  widthPx: number,
  crop: PdfCropBounds | null = null
): Promise<void> => {
  const base = page.getViewport({ scale: 1 });
  const userUnit = page.userUnit > 0 ? page.userUnit : 1;
  const plan = planCropRender({
    pageWidthPt: base.width / userUnit,
    pageHeightPt: base.height / userUnit,
    rotation: base.rotation,
    userUnit,
    crop,
    widthPx
  });
  const viewport = page.getViewport({
    scale: plan.pdfJsScale,
    offsetX: plan.offsetX,
    offsetY: plan.offsetY
  });
  const context = canvas.getContext('2d');

  if (!context) {
    throw new Error('Canvas 2D context is unavailable.');
  }

  // One crop-sized bitmap. The viewport offset clips the displayed page; no full-page canvas is allocated.
  canvas.width = plan.canvasWidth;
  canvas.height = plan.canvasHeight;
  context.fillStyle = '#ffffff';
  context.fillRect(0, 0, canvas.width, canvas.height);

  const parameters: RenderParameters = { canvas, viewport };

  await page.render(parameters).promise;
};

const openDocument = async (source: { url: string }): Promise<PdfRendererDocument> => {
  const loadingTask = getDocument({
    url: source.url,
    withCredentials: true,
    isEvalSupported: false,
    cMapUrl: pdfAssetUrl('cmaps'),
    cMapPacked: true,
    standardFontDataUrl: pdfAssetUrl('standard_fonts'),
    wasmUrl: pdfAssetUrl('wasm')
  });
  const pdf: PDFDocumentProxy = await loadingTask.promise;

  const pageAt = (page: number): Promise<PDFPageProxy> => {
    if (!Number.isInteger(page) || page < 1 || page > pdf.numPages) {
      return Promise.reject(new RangeError(`Page ${page} is outside 1-${pdf.numPages}.`));
    }

    return pdf.getPage(page);
  };

  return {
    pageCount: pdf.numPages,
    async getPageSize(page) {
      const viewport = (await pageAt(page)).getViewport({ scale: 1 });

      return { widthPt: viewport.width, heightPt: viewport.height };
    },
    async renderThumbnail(page, canvas, maxWidthPx) {
      await drawPage(await pageAt(page), canvas, Math.max(1, Math.round(maxWidthPx)));
    },
    async renderPagePng(page, widthPx, crop = null) {
      const width = Math.round(widthPx);

      if (width < MIN_RENDER_WIDTH || width > MAX_RENDER_WIDTH) {
        throw new RangeError(`Render width must be ${MIN_RENDER_WIDTH}-${MAX_RENDER_WIDTH} px.`);
      }

      const canvas = document.createElement('canvas');

      await drawPage(await pageAt(page), canvas, width, crop);

      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));

      canvas.width = 0;
      canvas.height = 0;

      if (!blob) {
        throw new Error('The page could not be encoded as PNG.');
      }

      return blob;
    },
    async destroy() {
      await loadingTask.destroy();
    }
  };
};

GlobalWorkerOptions.workerSrc = resolveWorkerSrc();

window.DocSyncWPPdfRenderer = {
  version: 1,
  open: openDocument
};
