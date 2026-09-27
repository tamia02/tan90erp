// Lazy-loaded rather than imported at the top level: tesseract.js +
// pdfjs-dist add ~450KB to this bundle, which app.js ships on every page
// in the app via the shared layout. Only Guard's Bill Scan screen ever
// calls this, so it's fetched as its own chunk on first use there instead
// of weighing down every other role's page load.
window.tan90ScanBillForPoNumber = (file) => import('./ocr').then((m) => m.scanForPoNumber(file));

// Shared local Search + Export controls for the app's rendered listings.
import './listing-tools';

// Keep open role dashboards in sync when another device changes the same
// workflow. This is deliberately a lightweight Livewire refresh instead of a
// full browser reload: it preserves navigation state and skips refreshes while
// a user is typing or has unsaved form values.
document.addEventListener('livewire:init', () => {
    let refreshInFlight = false;
    let pauseUntil = 0;

    const hasUnsavedFormValue = () => [...document.querySelectorAll('input, textarea, select')].some((field) => {
        if (field.type === 'file') {
            return field.files?.length > 0;
        }

        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.checked !== field.defaultChecked;
        }

        return field.value !== field.defaultValue;
    });

    const refreshOpenComponents = () => {
        if (document.visibilityState !== 'visible' || refreshInFlight || Date.now() < pauseUntil || hasUnsavedFormValue()) {
            return;
        }

        const components = typeof window.Livewire?.all === 'function' ? window.Livewire.all() : [];
        const requests = components
            .map((component) => {
                const root = component?.$wire?.$el ?? component?.el;
                const isAlreadyPolling = root && [...root.attributes].some(({ name }) => name.startsWith('wire:poll'));

                // Components with their own Livewire poll already keep their
                // queue current; refreshing them here as well doubles work.
                return isAlreadyPolling ? null : component?.$wire?.$refresh?.();
            })
            .filter(Boolean);

        if (! requests.length) {
            return;
        }

        refreshInFlight = true;
        Promise.allSettled(requests).finally(() => {
            refreshInFlight = false;
        });
    };

    // A 15-second interval is fast enough for operational queues without
    // creating a request storm on a page left open in the background.
    window.setInterval(refreshOpenComponents, 15000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            window.setTimeout(refreshOpenComponents, 250);
        }
    });
    document.addEventListener('input', () => {
        pauseUntil = Date.now() + 5000;
    }, true);
    document.addEventListener('focusin', (event) => {
        if (event.target.matches?.('input, textarea, select, [contenteditable="true"]')) {
            pauseUntil = Date.now() + 5000;
        }
    }, true);
});
