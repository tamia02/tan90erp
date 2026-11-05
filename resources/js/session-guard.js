// Keep a session alive while a worker is actively completing a form. Idle or
// hidden tabs retain the normal server timeout. No failed POST is ever retried.
let lastActivity = 0;
let pending = null;
let blocked = false;
let lastCheck = 0;
let lastAttempt = 0;
let waitingAction = false;
const recentlyActive = () => Date.now() - lastActivity < 5 * 60_000;

function showExpired() {
    blocked = true;
    if (document.getElementById('session-expired-notice')) return;
    const notice = document.createElement('div');
    notice.id = 'session-expired-notice';
    notice.setAttribute('role', 'alert');
    notice.style.cssText = 'position:sticky;top:0;z-index:1000;padding:16px;background:#fff3cd;color:#713f12;border-bottom:1px solid #d97706';
    notice.append(document.createTextNode('Your session has expired or your signed-in account changed. Your current form is still on this page. Sign in in another tab, then return here. '));
    const link = document.createElement('a');
    link.href = '/login';
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Sign in again';
    link.style.textDecoration = 'underline';
    notice.append(link);
    document.body.prepend(notice);
}

function checkSession(force = false) {
    const user = document.querySelector('meta[name="session-user"]')?.content;
    const url = document.querySelector('meta[name="session-check-url"]')?.content;
    if (pending) return pending;
    if (!user || !url || (!force && (document.visibilityState !== 'visible' || !recentlyActive()))) return Promise.resolve(false);
    lastAttempt = Date.now();
    pending = (async () => { try {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        if (response.status === 401) { showExpired(); return false; }
        if (!response.ok) return false;
        const session = await response.json();
        if (String(session.user_id) !== user) { showExpired(); return false; }
        if (typeof session.csrf_token !== 'string' || !session.csrf_token) return false;
        document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', session.csrf_token);
        document.querySelectorAll('input[name="_token"]').forEach(field => { field.value = session.csrf_token; });
        blocked = false;
        lastCheck = Date.now();
        document.getElementById('session-expired-notice')?.remove();
        document.getElementById('session-connection-notice')?.remove();
        return true;
    } catch {
        // A network failure is not evidence of logout. Keep the form intact.
        return false;
    } finally { pending = null; } })();
    return pending;
}

for (const event of ['input', 'pointerdown', 'keydown']) {
    document.addEventListener(event, () => {
        lastActivity = Date.now();
        if (lastActivity - lastAttempt > 60_000) checkSession();
    }, { capture: true, passive: true });
}
document.addEventListener('visibilitychange', () => { if (recentlyActive()) checkSession(); });
function guardAction(event, resume) {
    if (!document.querySelector('meta[name="session-user"]')?.content) return;
    if (!blocked && !pending && Date.now() - lastCheck < 60_000) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (waitingAction) return;
    waitingAction = true;
    lastActivity = Date.now();
    checkSession(true).then(valid => {
        if (valid) resume();
        else if (!blocked && !document.getElementById('session-connection-notice')) {
            const notice = document.createElement('div');
            notice.id = 'session-connection-notice';
            notice.setAttribute('role', 'alert');
            notice.textContent = 'Connection unavailable. Your form has not been submitted. Check your connection and try Save again.';
            notice.style.cssText = 'padding:16px;background:#fff3cd;color:#713f12';
            document.body.prepend(notice);
        }
    }).finally(() => { waitingAction = false; });
}
// Pause before the first POST after inactivity, including wire:click actions.
// Resume once after a successful GET; this never repeats a submitted POST.
document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || (form.method?.toLowerCase() === 'get' && !form.hasAttribute('wire:submit'))) return;
    const submitter = event.submitter;
    guardAction(event, () => form.requestSubmit(submitter || undefined));
}, true);
document.addEventListener('click', event => {
    const action = event.target.closest?.('button,a,[role="button"]');
    if (!action || ![...action.attributes].some(a => a.name === 'wire:click' || a.name.startsWith('wire:click.'))) return;
    guardAction(event, () => action.click());
}, true);
window.setInterval(checkSession, 60_000);
