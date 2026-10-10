// Every dropdown in the app is searchable: clicking a <select> opens a small
// panel with a search box over the same options. Picking one sets the real
// <select> and fires input/change, so wire:model, x-model and forms work as
// before. The panel lives on <body>, outside Livewire's DOM, so re-renders
// never touch it. Opt out with data-no-search on the <select>.

let panel = null;
let current = null;

const eligible = (el) => el instanceof HTMLSelectElement && !el.multiple && !el.disabled && el.size <= 1
    && !el.hasAttribute('data-no-search') && el.options.length > 0;

function close(refocus = false) {
    if (!panel) {
        return;
    }
    panel.remove();
    panel = null;
    const select = current;
    current = null;
    if (refocus && select?.isConnected) {
        select.focus();
    }
}

function choose(select, option) {
    if (option.disabled) {
        return;
    }
    close(true);
    if (select.value === option.value) {
        return;
    }
    select.value = option.value;
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

function open(select) {
    close();
    current = select;

    panel = document.createElement('div');
    panel.setAttribute('data-searchable-select', '');
    panel.style.cssText = 'position:fixed;z-index:9999;border:1px solid var(--border,#d1d5db);border-radius:12px;'
        + 'background:var(--surface-1,#fff);color:var(--text-primary,#111);box-shadow:0 12px 32px rgba(0,0,0,.18);'
        + 'display:flex;flex-direction:column;overflow:hidden;font-size:14px;';

    const input = document.createElement('input');
    input.type = 'search';
    input.placeholder = 'Type to search…';
    input.setAttribute('aria-label', 'Search options');
    input.autocomplete = 'off';
    input.style.cssText = 'margin:8px;padding:8px 10px;border:1px solid var(--border,#d1d5db);border-radius:8px;'
        + 'background:var(--surface-2,#f9fafb);color:inherit;font-size:14px;outline:none;';

    const list = document.createElement('div');
    list.setAttribute('role', 'listbox');
    list.style.cssText = 'overflow-y:auto;padding:0 4px 6px;';

    const empty = document.createElement('div');
    empty.textContent = 'No match';
    empty.style.cssText = 'padding:8px 10px;color:var(--text-muted,#6b7280);display:none;';

    const items = [...select.options].map((option) => {
        const row = document.createElement('div');
        row.setAttribute('role', 'option');
        const group = option.parentElement instanceof HTMLOptGroupElement ? option.parentElement.label : '';
        row.textContent = option.textContent.trim() || ' ';
        row.title = row.textContent;
        row.style.cssText = 'padding:7px 10px;border-radius:8px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;'
            + (option.disabled ? 'opacity:.45;cursor:default;' : '')
            + (option.selected ? 'font-weight:600;color:var(--brand,#4f46e5);' : '');
        row.addEventListener('mousedown', (e) => e.preventDefault());
        row.addEventListener('click', () => choose(select, option));
        list.appendChild(row);

        return { row, option, text: `${option.textContent} ${group}`.toLowerCase() };
    });
    list.appendChild(empty);

    let active = -1;
    const visible = () => items.filter((i) => i.row.style.display !== 'none' && !i.option.disabled);
    const highlight = (index) => {
        const shown = visible();
        items.forEach((i) => { i.row.style.background = ''; });
        active = Math.max(0, Math.min(index, shown.length - 1));
        if (shown[active]) {
            shown[active].row.style.background = 'var(--brand-bg,#eef2ff)';
            shown[active].row.scrollIntoView({ block: 'nearest' });
        }
    };
    const filter = () => {
        const words = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        let count = 0;
        items.forEach((i) => {
            const show = words.every((w) => i.text.includes(w));
            i.row.style.display = show ? '' : 'none';
            count += show ? 1 : 0;
        });
        empty.style.display = count ? 'none' : '';
        highlight(0);
    };

    input.addEventListener('input', filter);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            highlight(active + (e.key === 'ArrowDown' ? 1 : -1));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const pick = visible()[active];
            if (pick) {
                choose(select, pick.option);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            close(true);
        } else if (e.key === 'Tab') {
            close();
        }
    });

    panel.append(input, list);
    document.body.appendChild(panel);

    // Place under the dropdown (or above it when there is no room below).
    const rect = select.getBoundingClientRect();
    const width = Math.min(Math.max(rect.width, 260), window.innerWidth - 16);
    const below = window.innerHeight - rect.bottom - 8;
    const above = rect.top - 8;
    const height = Math.min(340, Math.max(below, above, 160));
    panel.style.width = `${width}px`;
    panel.style.maxHeight = `${height}px`;
    panel.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
    if (below >= Math.min(220, height) || below >= above) {
        panel.style.top = `${rect.bottom + 4}px`;
    } else {
        panel.style.bottom = `${window.innerHeight - rect.top + 4}px`;
    }

    const selectedIndex = visible().findIndex((i) => i.option.selected);
    highlight(selectedIndex > 0 ? selectedIndex : 0);
    input.focus({ preventScroll: true });
}

// Mouse: open the search panel instead of the browser's own list.
document.addEventListener('mousedown', (e) => {
    if (panel && !panel.contains(e.target)) {
        const reopenSame = e.target === current;
        close();
        if (reopenSame) {
            e.preventDefault();

            return;
        }
    }
    if (e.button === 0 && eligible(e.target)) {
        e.preventDefault();
        e.target.focus({ preventScroll: true });
        open(e.target);
    }
}, true);

// Touch: a tap (not a scroll) opens the panel; the phone's own picker is skipped.
let touchStart = null;
document.addEventListener('touchstart', (e) => {
    touchStart = eligible(e.target) ? { el: e.target, x: e.touches[0].clientX, y: e.touches[0].clientY } : null;
}, { capture: true, passive: true });
document.addEventListener('touchend', (e) => {
    const t = touchStart;
    touchStart = null;
    if (!t || t.el !== e.target) {
        return;
    }
    const p = e.changedTouches[0];
    if (Math.abs(p.clientX - t.x) > 10 || Math.abs(p.clientY - t.y) > 10) {
        return;
    }
    e.preventDefault();
    open(t.el);
}, { capture: true, passive: false });

// Keyboard: Enter / Space / Alt+Down on a focused dropdown opens the panel;
// typing letters on it still jumps through options like a normal dropdown.
document.addEventListener('keydown', (e) => {
    if (!panel && eligible(e.target) && (e.key === 'Enter' || e.key === ' ' || (e.altKey && e.key === 'ArrowDown') || e.key === 'F4')) {
        e.preventDefault();
        open(e.target);
    }
}, true);

const closeOnScroll = (e) => {
    if (panel && !panel.contains(e.target)) {
        close();
    }
};
window.addEventListener('scroll', closeOnScroll, true);
window.addEventListener('resize', () => close());
document.addEventListener('livewire:navigating', () => close());
