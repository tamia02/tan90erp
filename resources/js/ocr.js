import { createWorker } from 'tesseract.js';
import * as pdfjsLib from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.mjs?url';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

let workerPromise = null;

function getWorker() {
    // One worker reused across scans in a session -- creating a fresh one
    // per upload re-pays the WASM/language-data load cost (several
    // seconds) every single time, which would make repeated scans feel
    // broken rather than just slow.
    if (! workerPromise) {
        workerPromise = createWorker('eng');
    }

    return workerPromise;
}

/**
 * Renders page 1 of a PDF to a canvas -- Tesseract.js has no PDF support
 * of its own, it only reads images.
 */
async function pdfFirstPageToCanvas(file) {
    const buffer = await file.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: buffer }).promise;
    const page = await pdf.getPage(1);

    // 2x scale: sharper text for OCR than the PDF's native render size.
    const viewport = page.getViewport({ scale: 2 });
    const canvas = document.createElement('canvas');
    canvas.width = viewport.width;
    canvas.height = viewport.height;

    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

    return canvas;
}

/**
 * PO numbers in this app have no single enforced format (confirmed against
 * seed data: both "PO RM 2627 0020" and bare Zoho numeric IDs like
 * "898897889" are valid) -- so this tries a label first ("PO Number:",
 * "PO No.", "Purchase Order"), then falls back to shape-based patterns.
 */
function extractPoNumber(text) {
    const labelMatch = text.match(/P\.?\s*O\.?\s*(?:No\.?|Number|#)?\s*[:\-]\s*([A-Z0-9][A-Z0-9 \-\/]{3,24}[A-Z0-9])/i);
    if (labelMatch) {
        return labelMatch[1].replace(/\s+/g, ' ').trim();
    }

    const wordedMatch = text.match(/\bPO\s+[A-Z]{1,4}\s+\d{3,5}\s+\d{3,5}\b/i);
    if (wordedMatch) {
        return wordedMatch[0].replace(/\s+/g, ' ').trim().toUpperCase();
    }

    const numericMatch = text.match(/\b\d{8,10}\b/);
    if (numericMatch) {
        return numericMatch[0];
    }

    return null;
}

/**
 * Scans an uploaded bill file (image or PDF) for a PO number.
 * Returns null rather than throwing on any failure -- this is a
 * convenience autofill, never something that should block the manual
 * entry flow that already works.
 */
export async function scanForPoNumber(file) {
    try {
        const worker = await getWorker();
        const source = file.type === 'application/pdf' ? await pdfFirstPageToCanvas(file) : file;
        const { data } = await worker.recognize(source);

        return extractPoNumber(data.text || '');
    } catch (error) {
        console.warn('Bill scan OCR failed:', error);

        return null;
    }
}
