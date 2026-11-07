// Quest Log "Show me": navigate to the target page, then spotlight one element with a small coach-mark.
// Without JavaScript the link simply opens the page. Esc or "Got it" closes the coach-mark.
const KEY = 'seocc-show-me';

for (const link of document.querySelectorAll('a[data-show-me]')) {
    link.addEventListener('click', () => {
        try {
            const url = new URL(link.href, window.location.href);
            sessionStorage.setItem(KEY, JSON.stringify({ path: url.pathname, selector: link.dataset.showMe, label: link.dataset.showMeLabel || '' }));
        } catch { /* Storage unavailable: the link still opens the page. */ }
    });
}

const spotlight = () => {
    let target = null;
    try {
        const raw = sessionStorage.getItem(KEY);
        if (!raw) {
            return;
        }
        const wanted = JSON.parse(raw);
        if (wanted.path !== window.location.pathname) {
            return;
        }
        sessionStorage.removeItem(KEY);
        target = { element: document.querySelector(wanted.selector), label: String(wanted.label || '') };
    } catch {
        return;
    }
    if (!target.element) {
        return;
    }
    const element = target.element;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const mark = document.createElement('div');
    mark.className = 'quest-coachmark';
    mark.setAttribute('role', 'note');
    mark.setAttribute('aria-label', 'Quest Log hint');
    const text = document.createElement('p');
    text.textContent = target.label !== '' ? 'Quest Log: ' + target.label : 'Quest Log: this is the place for your current step.';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'button button-ghost button-small';
    close.textContent = 'Got it';
    mark.append(text, close);
    element.classList.add('quest-spotlight');
    // In a row of controls, the hint goes below the row rather than between its items.
    const parent = element.parentElement;
    const anchor = parent && parent !== document.body && getComputedStyle(parent).display.includes('flex') ? parent : element;
    anchor.insertAdjacentElement('afterend', mark);

    const focusable = element.matches('a, button, input, select, textarea, [tabindex]');
    if (!focusable) {
        element.setAttribute('tabindex', '-1');
    }
    element.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
    element.focus({ preventScroll: true });

    const dismiss = () => {
        element.classList.remove('quest-spotlight');
        if (!focusable) {
            element.removeAttribute('tabindex');
        }
        mark.remove();
        document.removeEventListener('keydown', onKey);
        element.focus({ preventScroll: true });
    };
    const onKey = (event) => {
        if (event.key === 'Escape') {
            dismiss();
        }
    };
    close.addEventListener('click', dismiss);
    document.addEventListener('keydown', onKey);
};
spotlight();

// Rank toasts are subtle: they fade out after a few seconds of reading time.
for (const toast of document.querySelectorAll('[data-quest-toast]')) {
    window.setTimeout(() => toast.classList.add('is-leaving'), 8000);
    window.setTimeout(() => toast.remove(), 9000);
}
