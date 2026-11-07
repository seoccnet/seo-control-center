// Hover layer for server-rendered line charts (performance dashboard).
// Each <svg data-points> holds [x, y, date, value] per point in viewBox units.
const numberFormat = new Intl.NumberFormat();

for (const figure of document.querySelectorAll('[data-chart]')) {
    const svg = figure.querySelector('svg[data-points]');
    const tooltip = figure.querySelector('.chart-tooltip');
    const crosshair = svg?.querySelector('.crosshair');
    const marker = svg?.querySelector('.marker');
    if (!svg || !tooltip || !crosshair || !marker) {
        continue;
    }
    let points;
    try {
        points = JSON.parse(svg.dataset.points || '[]');
    } catch {
        continue;
    }
    if (points.length === 0) {
        continue;
    }

    const show = (index) => {
        const [x, y, date, value] = points[index];
        crosshair.setAttribute('x1', x);
        crosshair.setAttribute('x2', x);
        marker.setAttribute('cx', x);
        marker.setAttribute('cy', y);
        // SVG elements have no .hidden property; toggle the attribute instead.
        crosshair.removeAttribute('hidden');
        marker.toggleAttribute('hidden', value === null);
        tooltip.textContent = `${date}: ${value === null ? 'Unavailable' : numberFormat.format(value) + (svg.dataset.suffix ?? '')}`;
        tooltip.hidden = false;
    };
    const hide = () => {
        crosshair.setAttribute('hidden', '');
        marker.setAttribute('hidden', '');
        tooltip.hidden = true;
    };
    const nearest = (clientX) => {
        const box = svg.getBoundingClientRect();
        const x = ((clientX - box.left) / box.width) * svg.viewBox.baseVal.width;
        let best = 0;
        for (let i = 1; i < points.length; i++) {
            if (Math.abs(points[i][0] - x) < Math.abs(points[best][0] - x)) {
                best = i;
            }
        }
        return best;
    };

    let current = points.length - 1;
    svg.addEventListener('pointermove', (event) => show(current = nearest(event.clientX)));
    svg.addEventListener('pointerleave', hide);
    // Keyboard: focus the chart and use the arrow keys to step through days.
    svg.setAttribute('tabindex', '0');
    svg.addEventListener('focus', () => show(current));
    svg.addEventListener('blur', hide);
    svg.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            current = Math.min(points.length - 1, Math.max(0, current + (event.key === 'ArrowRight' ? 1 : -1)));
            show(current);
            event.preventDefault();
        }
    });
}
