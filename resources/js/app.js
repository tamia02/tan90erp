// Lazy-loaded rather than imported at the top level: tesseract.js +
// pdfjs-dist add ~450KB to this bundle, which app.js ships on every page
// in the app via the shared layout. Only Guard's Bill Scan screen ever
// calls this, so it's fetched as its own chunk on first use there instead
// of weighing down every other role's page load.
window.tan90ScanBillForPoNumber = (file) => import('./ocr').then((m) => m.scanForPoNumber(file));
