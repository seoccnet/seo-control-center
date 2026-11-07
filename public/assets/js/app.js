// Shell behaviour for signed-in pages: dark/light toggle, auto-submitting selects and confirmations.
// The initial theme is applied by an inline script in <head>, so pages never flash.
import './table-controls.js';
import './section-help.js';
import './portfolio-controls.js';
import './insight-tabs.js';
import './module-navigation.js';
import './quests.js';
import './wordpress-plugin-notice.js';
import './market-select.js';

const navigationToggle = document.querySelector('[data-navigation-toggle]');
const sidebar = document.getElementById('app-navigation');
if (navigationToggle && sidebar) {
    document.documentElement.classList.add('ui-ready');
    navigationToggle.hidden = false;
    const mobileShell = matchMedia('(max-width: 60rem)');
    const scrim = document.querySelector('[data-navigation-dismiss]');
    const content = document.getElementById('content');
    const floating = document.querySelector('.assistant-launcher');
    const setNavigation = (open, restoreFocus = false) => {
        open = open && mobileShell.matches;
        document.body.classList.toggle('navigation-open', open);
        navigationToggle.setAttribute('aria-expanded', String(open));
        navigationToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        if (scrim) scrim.hidden = !open;
        if (content) content.inert = open;
        if (floating) floating.inert = open;
        if (open) sidebar.querySelector('a')?.focus();
        else if (restoreFocus) navigationToggle.focus();
    };
    navigationToggle.addEventListener('click', () => setNavigation(navigationToggle.getAttribute('aria-expanded') !== 'true', true));
    scrim?.addEventListener('click', () => setNavigation(false, true));
    mobileShell.addEventListener('change', () => setNavigation(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && navigationToggle.getAttribute('aria-expanded') === 'true') {
            setNavigation(false, true);
        }
        if (event.key === 'Tab' && navigationToggle.getAttribute('aria-expanded') === 'true') {
            const focusable = [...sidebar.querySelectorAll('a, button, select, summary, input')].filter(element => element.getClientRects().length && !element.disabled);
            focusable.push(navigationToggle);
            const index = focusable.indexOf(document.activeElement);
            const next = (index + (event.shiftKey ? -1 : 1) + focusable.length) % focusable.length;
            event.preventDefault();
            focusable[next]?.focus();
        }
    });
    document.addEventListener('click', (event) => {
        if (!sidebar.contains(event.target) && !navigationToggle.contains(event.target)) setNavigation(false);
    });
}
const projectGroups = [...document.querySelectorAll('[data-project-nav]')];
const narrowNavigation = window.matchMedia('(max-width: 48rem)');
const arrangeGroups = () => projectGroups.forEach((group) => { group.open = narrowNavigation.matches && !document.querySelector('.module-context') && group.hasAttribute('data-active-group'); });
arrangeGroups();
narrowNavigation.addEventListener('change', arrangeGroups);
for (const group of projectGroups) {
    group.addEventListener('toggle', () => {
        if (group.open) projectGroups.forEach((other) => { if (other !== group) other.open = false; });
    });
    group.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { group.open = false; group.querySelector('summary').focus(); }
        const links = [...group.querySelectorAll('a')];
        const index = links.indexOf(document.activeElement);
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            if (!group.open && !['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            event.preventDefault();
            group.open = true;
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? links.length - 1 : index < 0 ? (event.key === 'ArrowDown' ? 0 : links.length - 1) : (index + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
            links[next]?.focus();
        }
    });
    group.addEventListener('focusout', (event) => { if (!group.contains(event.relatedTarget)) group.open = false; });
}
document.addEventListener('click', (event) => {
    projectGroups.forEach((group) => { if (!group.contains(event.target)) group.open = false; });
});

const root = document.documentElement;
const toggle = document.querySelector('[data-theme-toggle]');

const current = () => (root.dataset.theme === 'light' ? 'light' : 'dark');
const label = () => {
    if (toggle) {
        toggle.setAttribute('aria-pressed', current() === 'light' ? 'true' : 'false');
    }
};

if (toggle) {
    label();
    toggle.addEventListener('click', () => {
        const next = current() === 'light' ? 'dark' : 'light';
        root.dataset.theme = next;
        try {
            localStorage.setItem('scc-theme', next);
        } catch {
            // Private mode or storage disabled: the choice lasts for this page only.
        }
        label();
    });
}

// Top bar menus close when clicking elsewhere or pressing Escape.
const menus = [...document.querySelectorAll('details[data-topbar-menu]')];
if (menus.length > 0) {
    document.addEventListener('click', (event) => {
        for (const menu of menus) {
            if (menu.open && !menu.contains(event.target)) {
                menu.open = false;
            }
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            menus.forEach((menu) => { menu.open = false; });
        }
    });
}

for (const select of document.querySelectorAll('select[data-autosubmit]')) {
    select.addEventListener('change', () => select.form?.requestSubmit());
}

// Forms with data-confirm ask before submitting (e.g. removing a member).
for (const form of document.querySelectorAll('form[data-confirm]')) {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm ?? 'Are you sure?')) {
            event.preventDefault();
        }
    });
}

// Pages showing work in progress (e.g. a running site audit) reload themselves
// while they are visible.
const refresh = document.querySelector('[data-refresh-seconds]');
if (refresh instanceof HTMLElement) {
    const seconds = Math.max(5, Number(refresh.dataset.refreshSeconds) || 15);
    window.setTimeout(function reload() {
        if (document.visibilityState === 'visible') {
            window.location.reload();
        } else {
            window.setTimeout(reload, seconds * 1000);
        }
    }, seconds * 1000);
}

// Consistent summary cards across the workspace; decorative icons never replace labels.
const summaryIconPaths = {
    pulse: 'M3 12h4l3-8 4 16 3-8h4',
    eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12 M15 12a3 3 0 1 0-6 0a3 3 0 1 0 6 0',
    search: 'M18 11a7 7 0 1 0-14 0a7 7 0 1 0 14 0 M20 20l-3.5-3.5',
    pages: 'M6 3h9l4 4v14H6z M14 3v5h5 M9 12h7 M9 16h7',
    shield: 'M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z',
    bolt: 'M13 2 4 14h7l-1 8 9-12h-7z',
    check: 'M5 12l4 4L19 6',
    clock: 'M20 12a8 8 0 1 0-16 0a8 8 0 1 0 16 0 M12 7v5l3 2',
    warning: 'M12 3 2 21h20z M12 9v5 M12 17v.1',
};
const summaryCards = '.stat, .kpi, .insight-metric, .portfolio-summary, .settings-summary > div, .forecast-estimate';
function decorateSummaryCard(card) {
    if (card.classList.contains('summary-tile')) return;
    const label = card.querySelector('.stat-label, .kpi-label, .portfolio-eyebrow, dt, .forecast-estimate > span');
    if (!label) return;
    const text = label.textContent.trim().toLowerCase();
    const icon = /error|warning|problem|fail|not cited/.test(text) ? 'warning'
        : /complete|done|resolved|conversion/.test(text) ? 'check'
        : /queued|running|month|today/.test(text) ? 'clock'
        : /impression|visibility|cited|overview/.test(text) ? 'eye'
        : /page|landing/.test(text) ? 'pages'
        : /health|check/.test(text) ? 'shield'
        : /click|session/.test(text) ? 'pulse'
        : /opportunit|revenue|request/.test(text) ? 'bolt' : 'search';
    card.classList.add('summary-tile');
    let badge = card.querySelector('.portfolio-summary-icon');
    if (!badge) {
        badge = document.createElement('span');
        badge.setAttribute('aria-hidden', 'true');
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        for (const [key, value] of Object.entries({viewBox:'0 0 24 24',fill:'none',stroke:'currentColor','stroke-width':'1.8','stroke-linecap':'round','stroke-linejoin':'round',focusable:'false'})) svg.setAttribute(key, value);
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', summaryIconPaths[icon]);
        svg.append(path); badge.append(svg); card.prepend(badge);
    }
    badge.classList.add('summary-tile-icon');
}
document.querySelectorAll(summaryCards).forEach(decorateSummaryCard);
// Forecast tiles are replaced when the horizon or selected metric changes.
for (const estimates of document.querySelectorAll('[data-forecast-estimates]')) {
    new MutationObserver(() => estimates.querySelectorAll('.forecast-estimate').forEach(decorateSummaryCard))
        .observe(estimates, {childList:true});
}
