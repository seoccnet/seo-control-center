// A per-user/project reminder only; hiding it does not enable or install a plugin.
const notice = document.querySelector('[data-wordpress-notice]');
if (notice) {
    const key = `seocc-wordpress-notice:${notice.dataset.wordpressNotice}`;
    try {
        notice.hidden = localStorage.getItem(key) === 'hidden';
    } catch { /* Keep the notice available when browser storage is blocked. */ }
    const dismiss = notice.querySelector('[data-wordpress-dismiss]');
    if (dismiss) {
        dismiss.hidden = false;
        dismiss.addEventListener('click', () => {
            try { localStorage.setItem(key, 'hidden'); } catch { /* Dismiss for this page only. */ }
            notice.hidden = true;
            document.getElementById('overview-heading')?.focus();
        });
    }
}
