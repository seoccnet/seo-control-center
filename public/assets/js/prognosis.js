// Daily observations retain their dates: missing imports never become zeroes.
// The model carries this script's version, so a release never pairs it with a cached older model.
const { forecastMetric, forecastSearch } = await import('./forecast-model.js' + new URL(import.meta.url).search);
const DAY = 86400000;
const metrics = [
    { key: 'clicks', label: 'Clicks', color: 'var(--accent-strong)', digits: 0 },
    { key: 'impressions', label: 'Impressions', color: 'var(--positive)', digits: 0 },
    { key: 'ctr', label: 'CTR', color: 'var(--warning)', digits: 2, suffix: '%' },
    { key: 'position', label: 'Position', color: 'var(--forecast-position)', digits: 1 },
];
export function projectMetric(days, key, horizon) {
    return forecastMetric(days, key, horizon).points;
}
// Position is drawn upside down so that higher on the chart is always better; its best value, 1, is the top.
export const plotY = (metric, value, scale) => metric.key === 'position' ? 18 + (value - 1) / Math.max(1e-9, scale - 1) * 230 : 248 - value / scale * 230;
// Axis end for one metric: a round number above the peak, and for position 1 plus four round steps.
export function axisScale(key, peak) {
    if (key !== 'position') {
        const step = 10 ** Math.floor(Math.log10(Math.max(1, peak)));
        return Math.ceil(Math.max(1, peak) / step) * step;
    }
    const raw = Math.max(1, peak - 1) / 4;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    return 1 + 4 * [1, 2, 2.5, 5, 10].map(factor => factor * magnitude).find(step => step >= raw);
}
// Trailing 7-day mean of the daily values ending on each date; needs at least five of the seven days.
export function weekAverage(values, dates) {
    return dates.map(date => {
        const end = Date.parse(date + 'T00:00:00Z');
        const window = [];
        for (let day = 0; day < 7; day++) {
            const value = values.get(new Date(end - day * DAY).toISOString().slice(0, 10));
            if (Number.isFinite(value)) window.push(value);
        }
        return { date, value: window.length >= 5 ? window.reduce((sum, value) => sum + value, 0) / window.length : null };
    });
}
// One sentence comparing each projected end value with the average of the last seven recorded days.
export function summarize(days, forecasts, count) {
    const parts = [];
    for (const metric of metrics) {
        const end = forecasts[metric.key]?.at(-1)?.value;
        const recent = days.slice(-7).map(day => day[metric.key]).filter(Number.isFinite);
        if (!Number.isFinite(end) || recent.length < 5) continue;
        const now = recent.reduce((sum, value) => sum + value, 0) / recent.length;
        const change = now > 0 ? (end - now) / now : 0;
        const better = metric.key === 'position' ? change < 0 : change > 0;
        const trend = Math.abs(change) < .05 ? 'steady at' : (metric.key === 'position' ? (better ? 'improving from' : 'worsening from') : (better ? 'rising from' : 'falling from'));
        const name = { clicks: 'clicks about', impressions: 'impressions about', ctr: 'CTR about', position: 'average position about' }[metric.key];
        const unit = ['clicks', 'impressions'].includes(metric.key) ? ' a day' : '';
        parts.push(`${name} ${format(metric, end)}${unit} (${trend} ${format(metric, now)})`);
    }
    return parts.length ? `In ${count} days, if recent patterns continue: ${parts.join(', ')}. Compared with the average of the last 7 recorded days; a projection, not a promise.` : '';
}
const format = (metric, value) => value == null ? '—' : new Intl.NumberFormat(undefined, { maximumFractionDigits: metric.digits }).format(value) + (metric.suffix ?? '');
const ns = 'http://www.w3.org/2000/svg';
const make = (tag, attributes, text) => {
    const element = document.createElementNS(ns, tag);
    Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, String(value)));
    if (text !== undefined) element.textContent = text;
    return element;
};
for (const card of document.querySelectorAll('[data-prognosis]')) {
    const days = JSON.parse(card.dataset.days || '[]');
    const history = JSON.parse(card.dataset.history || '[]');
    // Google's not-yet-final days after the last recorded day: compared with the projection, never used to fit it.
    const preliminary = JSON.parse(card.dataset.preliminary || '[]');
    const cache = new Map();
    if (!days.length) continue;
    const svg = card.querySelector('[data-forecast-svg]');
    const horizon = card.querySelector('[data-horizon]');
    const readout = card.querySelector('[data-forecast-readout]');
    const reduce = matchMedia('(prefers-reduced-motion: reduce)');
    let selected = 'all', smooth = false, inspected = days.length - 1, records = days, cursor, cursorX, cursorScales;
    const dateLabel = date => new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(date + 'T00:00:00Z'));
    const render = (animate = false) => {
        const count = Number(horizon.value);
        const width = Math.max(360, Math.min(1000, card.clientWidth - 48));
        const left = 52, right = width - 20, plotWidth = right - left;
        svg.setAttribute('viewBox', '0 0 ' + width + ' 300');
        if (!cache.has(count)) cache.set(count, forecastSearch(history, count, card.dataset.asOf));
        const evaluation = cache.get(count);
        const daily = Object.fromEntries(metrics.map(metric => [metric.key, evaluation[metric.key].points]));
        const hasForecast = metrics.some(metric => daily[metric.key].length);
        // The 7-day view averages recorded and projected days alike; partial days and daily error bands stay out of it.
        const known = Object.fromEntries(metrics.map(metric => [metric.key, new Map([...history, ...days].map(day => [day.date, day[metric.key]]))]));
        const forecasts = smooth ? Object.fromEntries(metrics.map(metric => {
            const values = new Map(known[metric.key]);
            daily[metric.key].slice(1).forEach(point => values.set(point.date, point.value));
            return [metric.key, weekAverage(values, daily[metric.key].map(point => point.date))];
        })) : daily;
        const shownDays = smooth ? days.map((day, index) => ({ ...day, ...Object.fromEntries(metrics.map(metric => [metric.key, weekAverage(known[metric.key], [day.date])[0].value])) })) : days;
        const summary = card.querySelector('[data-forecast-summary]');
        if (summary) { summary.textContent = summarize(days, daily, count); summary.hidden = summary.textContent === ''; }
        const first = Date.parse(days[0].date + 'T00:00:00Z');
        const last = Date.parse(days.at(-1).date + 'T00:00:00Z');
        const extra = preliminary.filter(row => row.date > days.at(-1).date);
        // Days Google is still collecting are partial totals: shown in the readout, never plotted or scaled.
        const plotted = smooth ? [] : extra.filter(row => row.complete);
        const end = Math.max(last + (hasForecast ? count * DAY : 0), ...plotted.map(row => Date.parse(row.date + 'T00:00:00Z')));
        const x = date => left + (Date.parse(date + 'T00:00:00Z') - first) / Math.max(DAY, end - first) * plotWidth;
        svg.querySelectorAll('[data-rendered]').forEach(element => element.remove());
        const group = make('g', { 'data-rendered': '' });
        svg.append(group);
        const markers = [...svg.querySelectorAll('[data-change-date]')].sort((a, b) => a.dataset.changeDate.localeCompare(b.dataset.changeDate));
        const runs = [];
        for (const marker of markers) {
            const date = marker.dataset.changeDate;
            const visible = date >= days[0].date && date <= days.at(-1).date;
            marker.toggleAttribute('hidden', !visible);
            marker.setAttribute('x1', x(date));
            marker.setAttribute('x2', x(date));
            svg.append(marker);
            if (!visible) continue;
            const run = runs.at(-1);
            if (run && Date.parse(date + 'T00:00:00Z') - Date.parse(run.at(-1).dataset.changeDate + 'T00:00:00Z') <= DAY) run.push(marker);
            else runs.push([marker]);
        }
        // Changes on consecutive days form one shaded band instead of a line per day.
        for (const run of runs.filter(run => run.length > 1)) {
            run.forEach(marker => marker.setAttribute('hidden', ''));
            const from = run[0].dataset.changeDate, to = run.at(-1).dataset.changeDate;
            const band = make('rect', { x: x(from), y: 18, width: Math.max(2, x(to) - x(from)), height: 230, class: 'annotation-band' });
            band.append(make('title', {}, `Recorded changes · ${dateLabel(from)} – ${dateLabel(to)} · see SEO change context below`));
            group.append(band);
        }
        if (hasForecast) {
            group.append(make('rect', { x: x(days.at(-1).date), y: 18, width: right - x(days.at(-1).date), height: 230, class: 'forecast-zone', rx: 10 }));
            group.append(make('line', { x1: x(days.at(-1).date), x2: x(days.at(-1).date), y1: 18, y2: 248, class: 'forecast-boundary' }));
            group.append(make('text', { x: x(days.at(-1).date) + 8, y: 14, class: 'forecast-axis' }, width < 600 ? 'Estimate' : 'Projected trend'));
        }
        const shown = metrics.filter(metric => selected === 'all' || metric.key === selected);
        // The daily error band, for one selected metric at a time so bands never overlap.
        const errorBand = selected !== 'all' && !smooth ? evaluation[selected].band ?? [] : [];
        // The axis follows the recorded and projected values; a wider error band is clipped at the chart edge.
        const scales = Object.fromEntries(shown.map(metric => {
            const values = [...shownDays.map(day => day[metric.key]), ...forecasts[metric.key].map(point => point.value), ...plotted.map(row => row[metric.key])].filter(Number.isFinite);
            const peak = Math.max(metric.key === 'position' ? 2 : 1, ...values);
            return [metric.key, selected === 'all' ? peak : axisScale(metric.key, peak)];
        }));
        let clipped = false;
        for (let tick = 0; tick <= 4; tick++) {
            const y = 248 - tick * 57.5;
            group.append(make('line', { x1: left, x2: right, y1: y, y2: y, class: 'forecast-gridline' }));
            // The position axis runs downwards from 1 at the top.
            const value = selected === 'all' ? tick / 4 * 100 : selected === 'position' ? scales.position - tick / 4 * (scales.position - 1) : tick / 4 * scales[selected];
            group.append(make('text', { x: left - 10, y: y + 4, 'text-anchor': 'end', class: 'forecast-axis' }, selected === 'all' ? String(value) : format(shown[0], value)));
        }
        for (const metric of shown) {
            const y = value => plotY(metric, value, scales[metric.key]);
            const path = points => {
                let previous = null;
                return points.map(point => {
                    if (!Number.isFinite(point.value)) { previous = null; return ''; }
                    const stamp = Date.parse(point.date + 'T00:00:00Z');
                    const command = previous !== null && stamp - previous <= DAY ? 'L' : 'M';
                    previous = stamp;
                    return `${command}${x(point.date).toFixed(2)},${y(point.value).toFixed(2)}`;
                }).join(' ');
            };
            if (errorBand.length) {
                const edge = value => {
                    const raw = y(value);
                    if (raw < 18 || raw > 248) clipped = true;
                    return Math.min(248, Math.max(18, raw)).toFixed(2);
                };
                const outline = [...errorBand.map(point => `${x(point.date).toFixed(2)},${edge(point.upper)}`), ...[...errorBand].reverse().map(point => `${x(point.date).toFixed(2)},${edge(point.lower)}`)];
                const area = make('polygon', { points: outline.join(' '), fill: metric.color, class: 'forecast-band' });
                area.append(make('title', {}, `${metric.label} · historical error band: 80% of past forecasts for each day ahead fell inside it`));
                group.append(area);
            }
            group.append(make('path', { d: path(shownDays.map(day => ({ date: day.date, value: day[metric.key] }))), stroke: metric.color, class: 'forecast-line' }));
            const estimate = forecasts[metric.key];
            if (estimate.length) group.append(make('path', { d: path(estimate), stroke: metric.color, class: 'forecast-line forecast-dashed' }));
            // Not-yet-final days continue the recorded line as a dotted segment, so they read as its latest values.
            const latest = plotted.filter(row => Number.isFinite(row[metric.key]));
            if (latest.length) {
                const start = Number.isFinite(shownDays.at(-1)[metric.key]) ? [{ date: shownDays.at(-1).date, value: shownDays.at(-1)[metric.key] }] : [];
                group.append(make('path', { d: path([...start, ...latest.map(row => ({ date: row.date, value: row[metric.key] }))]), stroke: metric.color, class: 'forecast-line forecast-preliminary-line' }));
            }
            for (const row of latest) {
                const marker = make('circle', { cx: x(row.date).toFixed(2), cy: y(row[metric.key]).toFixed(2), r: 3, fill: metric.color, class: 'forecast-preliminary' });
                marker.append(make('title', {}, `${metric.label} · ${dateLabel(row.date)} · not final yet: ${format(metric, row[metric.key])}`));
                group.append(marker);
            }
        }
        const extended = end > last;
        for (const date of [days[0].date, days.at(-1).date, ...(extended ? [new Date(end).toISOString().slice(0, 10)] : [])]) {
            group.append(make('text', { x: x(date), y: 281, 'text-anchor': date === days[0].date ? 'start' : date === days.at(-1).date && extended ? 'middle' : 'end', class: 'forecast-axis' }, dateLabel(date)));
        }
        cursorX = x; cursorScales = scales;
        cursor = make('g', { class: 'forecast-cursor', 'aria-hidden': 'true' });
        group.append(cursor);
        records = shownDays.map(day => ({ ...day, average: smooth }));
        if (hasForecast) for (let day = 1; day <= count; day++) {
            records.push({ date: new Date(last + day * DAY).toISOString().slice(0, 10), projected: true, average: smooth, ...Object.fromEntries(metrics.map(metric => [metric.key, forecasts[metric.key][day]?.value ?? null])) });
        }
        for (const row of extra) {
            const record = records.find(item => item.date === row.date);
            if (record) record.preliminary = row;
            else records.push({ date: row.date, projected: false, preliminary: row, ...Object.fromEntries(metrics.map(metric => [metric.key, null])) });
        }
        records.sort((a, b) => a.date.localeCompare(b.date));
        const estimates = card.querySelector('[data-forecast-estimates]');
        estimates.replaceChildren();
        for (const metric of metrics) {
            const tile = document.createElement('div');
            tile.className = `forecast-estimate metric-${metric.key}`;
            const label = document.createElement('span'), value = document.createElement('strong'), note = document.createElement('span');
            label.textContent = metric.label;
            value.textContent = format(metric, daily[metric.key].at(-1)?.value);
            const result = evaluation[metric.key];
            // The tile's range is the band's last day when a band exists, so both always agree.
            const range = result.band?.length ? { ...result.band.at(-1), sample: result.range?.sample } : result.range;
            note.textContent = daily[metric.key].length ? (range ? `Historical error range: ${format(metric, range.lower)}–${format(metric, range.upper)}${range.sample ? ` · ${range.sample} endpoints` : ''}` : `+${count} days · insufficient tests for error range`) : result.reason || 'Aligned impressions and CTR unavailable';
            tile.append(label, value, note); estimates.append(tile);
        }
        const validation = card.querySelector('[data-forecast-validation]');
        validation.replaceChildren();
        for (const metric of metrics) {
            const result = evaluation[metric.key];
            const row = document.createElement('tr');
            const cells = [metric.label + ' · ' + (result.method || 'Unavailable'), result.mae === null || result.mae === undefined ? 'Not validated' : metric.key === 'ctr' ? result.mae.toFixed(2) + ' pp' : format(metric, result.mae), result.folds ? `${result.folds} weekly origins · ${result.sample} scored predictions${metric.key === 'clicks' ? ' · old linear MAE ' + format(metric, result.baselineMae) + '; compound MAE ' + format(metric, result.pipelineMae) : ''}` : result.reason || 'Fewer than four shared historical origins'];
            for (const text of cells) { const cell = document.createElement('td'); cell.textContent = text; row.append(cell); }
            validation.append(row);
        }
        const inputs = evaluation.ctr.inputTests || [];
        card.querySelector('[data-forecast-input-test]').textContent = (inputs.length ? inputs.map(input => `${input.name}: ${input.status}${input.folds ? ` · ${input.folds} shared origins; error ${input.baselineMae.toFixed(2)} → ${input.inputMae.toFixed(2)} pp` : ''}`).join('. ') : 'Additional inputs: CTR history unavailable') + '. Future inputs are estimated from past observations. Other dashboard evidence is context, not an assumed traffic uplift.';
        const models = card.querySelector('[data-forecast-models]');
        models.replaceChildren();
        for (const metric of metrics.filter(metric => metric.key !== 'clicks')) {
            const result = evaluation[metric.key], paragraph = document.createElement('p');
            paragraph.className = 'muted small';
            paragraph.textContent = metric.label + ': ' + (result.candidates?.length ? result.candidates.map(model => `${model.name} · MAE ${model.mae.toFixed(2)}${metric.key === 'ctr' ? ' pp' : ''}`).join('; ') + ` · ${result.candidates[0].folds} shared origins.` : 'Not enough shared historical origins to compare methods.');
            models.append(paragraph);
        }
        const source = evaluation.impressions;
        card.querySelector('[data-forecast-method]').textContent = `${selected === 'all' ? 'Relative scale · each metric’s peak = 100 · position drawn upside down (higher is better)' : selected === 'position' ? 'Actual positions · axis upside down (higher is better)' : 'Actual metric values'}${smooth ? ' · 7-day averages of the daily values' : ''} · Solid: recorded · Dashed: projected${errorBand.length ? ' · Shaded: historical error band' + (clipped ? ' (wider than the chart; see the tile for its full range)' : '') : !smooth && hasForecast ? (selected === 'all' ? ' · Choose one metric to see its error band' : ' · Too few backtests for a daily error band') : ''} · ${hasForecast ? `Training: ${source.from}–${source.to}, ${source.history} observations · latest data ${source.lag} day${source.lag === 1 ? '' : 's'} old. Historical accuracy does not guarantee future accuracy.` : source.reason || 'More recent data needed to forecast.'}`;
        card.querySelector('[data-forecast-canvas]').hidden = false;
        if (animate && !reduce.matches) group.animate([{ opacity: 0, clipPath: 'inset(0 100% 0 0)' }, { opacity: 1, clipPath: 'inset(0 0 0 0)' }], { duration: 250, easing: 'cubic-bezier(0.23, 1, 0.32, 1)' });
        inspected = Math.min(inspected, records.length - 1);
        inspect(inspected);
    };
    const inspect = index => {
        const row = records[index];
        readout.replaceChildren();
        const date = document.createElement('span');
        date.className = 'forecast-inspected-date';
        const early = row.preliminary;
        date.textContent = dateLabel(row.date) + (row.projected ? ' · Estimated' : early ? '' : ' · Recorded') + (row.average ? ' · 7-day average' : '') + (early ? ' · Not final yet' + (early.complete ? '' : ', still collecting') : '');
        readout.append(date);
        cursor.replaceChildren(make('line', { x1: cursorX(row.date), x2: cursorX(row.date), y1: 18, y2: 248, class: 'forecast-crosshair' }));
        for (const metric of metrics.filter(metric => selected === 'all' || selected === metric.key)) {
            const item = document.createElement('span'), label = document.createElement('span'), value = document.createElement('strong');
            item.className = 'forecast-inspected-metric metric-' + metric.key;
            label.textContent = metric.label;
            // Estimated and preliminary values side by side when both exist for the day.
            value.textContent = early ? (row.projected ? format(metric, row[metric.key]) + ' est. · ' : '') + format(metric, early[metric.key]) + ' so far' : format(metric, row[metric.key]);
            item.append(label, value); readout.append(item);
            if (Number.isFinite(row[metric.key])) cursor.append(make('circle', { cx: cursorX(row.date), cy: plotY(metric, row[metric.key], cursorScales[metric.key]), r: 4, fill: metric.color, class: 'forecast-point' }));
        }
    };
    card.querySelectorAll('[data-metric]').forEach(button => button.addEventListener('click', event => {
        selected = button.dataset.metric;
        card.querySelectorAll('[data-metric]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        render(event.detail > 0);
    }));
    horizon.addEventListener('change', () => render());
    card.querySelectorAll('[data-smooth]').forEach(button => button.addEventListener('click', event => {
        smooth = button.dataset.smooth === '7';
        card.querySelectorAll('[data-smooth]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        render(event.detail > 0);
    }));
    svg.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        event.preventDefault();
        inspected = Math.max(0, Math.min(records.length - 1, inspected + (event.key === 'ArrowRight' ? 1 : -1)));
        inspect(inspected);
    });
    const inspectPointer = event => {
        const box = svg.getBoundingClientRect();
        const ratio = Math.max(0, Math.min(1, ((event.clientX - box.left) / box.width * svg.viewBox.baseVal.width - 52) / (svg.viewBox.baseVal.width - 72)));
        const first = Date.parse(records[0].date), last = Date.parse(records.at(-1).date);
        const target = first + ratio * (last - first);
        inspected = records.reduce((best, row, index) => Math.abs(Date.parse(row.date) - target) < Math.abs(Date.parse(records[best].date) - target) ? index : best, 0);
        inspect(inspected);
    };
    svg.addEventListener('pointermove', inspectPointer);
    svg.addEventListener('pointerdown', inspectPointer);
    svg.addEventListener('pointerleave', () => {
        inspected = records.findIndex(row => row.date === days.at(-1).date);
        inspect(inspected);
    });
    render(true);
    let previousWidth = card.clientWidth;
    new ResizeObserver(() => {
        if (Math.abs(card.clientWidth - previousWidth) < 1) return;
        previousWidth = card.clientWidth;
        render();
    }).observe(card);
}
