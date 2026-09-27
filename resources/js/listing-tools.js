const SEARCH_HIDDEN = 'tan90-search-hidden';

if (!document.getElementById('tan90-listing-tools-style')) {
    const style = document.createElement('style');
    style.id = 'tan90-listing-tools-style';
    style.textContent = `.${SEARCH_HIDDEN}{display:none!important}`;
    document.head.append(style);
}

const cellText = (cell) => (cell.innerText || cell.textContent || '').replace(/\s+/g, ' ').trim();
const quoteCsv = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;

const downloadCsv = (rows, filePrefix) => {
    const csv = rows.map((row) => row.map(quoteCsv).join(',')).join('\r\n');
    const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${filePrefix}-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
};

const readableName = (element, fallback) => {
    const heading = element.closest('section')?.querySelector('h2, h3')
        || element.parentElement?.querySelector(':scope > h2, :scope > h3')
        || element.closest('main')?.querySelector('h1');
    const name = cellText(heading || document.createElement('span'));
    return (name || fallback).toLocaleLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || fallback;
};

const isBaselineVisible = (record) => {
    const wasSearchHidden = record.classList.contains(SEARCH_HIDDEN);
    if (wasSearchHidden) record.classList.remove(SEARCH_HIDDEN);
    const visible = !record.hidden
        && record.getAttribute('aria-hidden') !== 'true'
        && getComputedStyle(record).display !== 'none'
        && record.getClientRects().length > 0;
    if (wasSearchHidden) record.classList.add(SEARCH_HIDDEN);
    return visible;
};

const makeToolbar = (records, getText, exportRows, listName) => {
    const bar = document.createElement('div');
    bar.className = 'tan90-listing-tools';
    bar.setAttribute('role', 'search');
    Object.assign(bar.style, {
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        gap: '0.5rem',
        margin: '0 0 0.65rem',
    });

    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = `Search ${listName.replaceAll('-', ' ')}`;
    search.setAttribute('aria-label', `Search ${listName.replaceAll('-', ' ')}`);
    Object.assign(search.style, {
        minWidth: '12rem',
        minHeight: '2.75rem',
        flex: '1 1 14rem',
        maxWidth: '24rem',
        border: '1px solid var(--border)',
        borderRadius: '0.6rem',
        padding: '0.55rem 0.75rem',
        color: 'var(--text-primary)',
        background: 'var(--surface-1)',
    });

    const exportButton = document.createElement('button');
    exportButton.type = 'button';
    exportButton.textContent = 'Export CSV';
    exportButton.setAttribute('aria-label', `Export ${listName.replaceAll('-', ' ')} as CSV`);
    Object.assign(exportButton.style, {
        border: '1px solid var(--border)',
        borderRadius: '0.6rem',
        minHeight: '2.75rem',
        padding: '0.55rem 0.75rem',
        fontSize: '0.875rem',
        fontWeight: '600',
        color: 'var(--text-primary)',
        background: 'var(--surface-1)',
        cursor: 'pointer',
    });

    const count = document.createElement('span');
    count.className = 'tan90-listing-count';
    count.setAttribute('aria-live', 'polite');
    count.setAttribute('role', 'status');
    count.style.cssText = 'font-size:.75rem;color:var(--text-muted);';

    const noResults = document.createElement('span');
    noResults.className = 'tan90-listing-empty';
    noResults.textContent = 'No matching records. Change or clear your search.';
    noResults.setAttribute('role', 'status');
    noResults.style.cssText = 'display:none; flex-basis:100%; font-size:.75rem; color:var(--text-muted);';

    const visibleRecords = () => records().filter((record) =>
        isBaselineVisible(record) && !record.classList.contains(SEARCH_HIDDEN));

    const update = () => {
        const query = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        records().forEach((record) => {
            if (!isBaselineVisible(record)) return;
            const matches = !query || getText(record).toLocaleLowerCase().includes(query);
            record.classList.toggle(SEARCH_HIDDEN, !matches);
            if (matches) visible += 1;
        });
        count.textContent = `${visible} shown`;
        noResults.style.display = query && visible === 0 ? 'block' : 'none';
        exportButton.disabled = visible === 0;
        exportButton.style.opacity = visible === 0 ? '0.55' : '1';
        exportButton.style.cursor = visible === 0 ? 'not-allowed' : 'pointer';
    };

    search.addEventListener('input', update);
    exportButton.addEventListener('click', () => {
        const rows = exportRows(visibleRecords());
        if (rows.length > 1) downloadCsv(rows, listName);
    });
    bar.append(search, exportButton, count, noResults);
    update();

    return bar;
};

const cardLike = (element) => element instanceof HTMLElement
    && element.classList.contains('border')
    && [...element.classList].some((className) => className.startsWith('rounded'));

const initializeListingTools = () => {
    const main = document.querySelector('main');
    if (!main) return;

    main.querySelectorAll('table').forEach((table, index) => {
        if (table.closest('[data-listing-ignore]') || !isBaselineVisible(table)) return;
        const records = () => [...table.querySelectorAll('tbody tr')];
        // The toolbar is inserted as the table's previous sibling inside its
        // wrapper. Checking the wrapper's previous sibling missed it, so the
        // observer appended another toolbar on every DOM mutation.
        const existing = table.previousElementSibling;
        // Detail/report tables with one row do not need list controls. Keeping
        // the generic toolbar for them made a single vendor GRN look like a
        // searchable multi-record queue.
        if (records().length < 2) {
            if (existing?.classList.contains('tan90-listing-tools')) existing.remove();
            delete table.dataset.listingTools;
            return;
        }
        if (existing?.classList.contains('tan90-listing-tools')) {
            table.dataset.listingTools = 'ready';
            existing.querySelector('input[type="search"]')?.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }

        table.dataset.listingTools = 'ready';
        const header = [...table.querySelectorAll('thead tr:first-child th')].map(cellText);
        const listName = readableName(table, `table-${index + 1}`);
        const tools = makeToolbar(
            records,
            cellText,
            (visible) => [header, ...visible.map((row) => [...row.cells].map(cellText))],
            listName,
        );
        table.parentElement?.insertBefore(tools, table);
    });

    main.querySelectorAll('div.flex.flex-col').forEach((group, index) => {
        if (group.closest('[data-listing-ignore]') || !isBaselineVisible(group)) return;
        // Tables own their own row toolbar; decorating a card wrapper around a
        // table produces duplicate search/export controls for the same data.
        if (group.querySelector('table')) return;

        const records = [...group.children].filter((record) =>
            cardLike(record) && !record.closest('[data-listing-ignore]'));
        // A single bordered field/control is a common form pattern, not a
        // listing. Only repeated bordered cards qualify for list controls.
        if (records.length < 2) return;

        const existing = group.previousElementSibling;
        if (group.dataset.listingTools === 'ready' && existing?.classList.contains('tan90-listing-tools')) {
            existing.querySelector('input[type="search"]')?.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }

        group.dataset.listingTools = 'ready';
        records.forEach((record) => { record.dataset.listingRecord = 'true'; });
        const listName = readableName(group, `list-${index + 1}`);
        const tools = makeToolbar(
            () => [...group.children].filter((child) => child.dataset.listingRecord === 'true'),
            cellText,
            (visible) => [['Record'], ...visible.map((record) => [cellText(record)])],
            listName,
        );
        group.parentElement?.insertBefore(tools, group);
    });
};

let refreshTimer;
const scheduleRefresh = () => {
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(initializeListingTools, 40);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeListingTools, { once: true });
} else {
    initializeListingTools();
}

if (window.Livewire) {
    document.addEventListener('livewire:navigated', scheduleRefresh);
    document.addEventListener('livewire:initialized', () => {
        window.Livewire.hook('morph.updated', scheduleRefresh);
    });
}

document.querySelector('main')?.addEventListener('click', scheduleRefresh);
document.querySelector('main')?.addEventListener('change', scheduleRefresh);
document.querySelector('main')?.addEventListener('toggle', scheduleRefresh, true);

new MutationObserver(scheduleRefresh).observe(document.documentElement, {
    childList: true,
    subtree: true,
});
