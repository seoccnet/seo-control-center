// "Print / Save as PDF" buttons (the report page); inline handlers are blocked by the CSP.
for (const button of document.querySelectorAll('[data-print]')) {
    button.addEventListener('click', () => window.print());
}
