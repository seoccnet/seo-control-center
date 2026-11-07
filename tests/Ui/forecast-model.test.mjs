import assert from 'node:assert/strict';
import { forecastMetric, forecastSearch } from '../../public/assets/js/forecast-model.js';
const start = Date.UTC(2026, 0, 1), DAY = 86400000;
const date = index => new Date(start + index * DAY).toISOString().slice(0, 10);
const history = Array.from({ length: 180 }, (_, index) => {
    const weekday = new Date(start + index * DAY).getUTCDay();
    const impressions = [600, 1400, 1500, 1600, 1400, 1200, 500][weekday];
    return { date: date(index), impressions, ctr: 5, clicks: impressions * .05, position: 8 };
});
for (const horizon of [7, 14, 28]) {
    const result = forecastSearch(history, horizon, date(180));
    assert.equal(result.impressions.method, 'Weekday average');
    assert.equal(result.impressions.mae, 0);
    assert.ok(result.impressions.folds >= 4);
    assert.ok(result.clicks.mae < result.clicks.baselineMae, 'Coherent weekday forecast beats old linear click extrapolation');
    assert.ok(result.impressions.range, 'Endpoint envelope requires eight origins');
    assert.equal(result.impressions.points[0].date, date(179));
    assert.equal(result.impressions.points.at(-1).date, date(179 + horizon));
    for (let step = 0; step <= horizon; step++) {
        assert.equal(result.clicks.points[step].value, result.impressions.points[step].value * result.ctr.points[step].value / 100);
        assert.equal(result.impressions.points[step].value, history[(179 + step) % 7].impressions);
    }
    assert.equal(result.ctr.inputTest.accepted, false);
}
assert.deepEqual(forecastMetric([], 'clicks', 14).points, []);
const constantClicks = history.slice(0, 81).map((row, index) => {
    const impressions = 1000 + index * 7 + (index * 17 % 11) * 20;
    return { ...row, impressions, clicks: 10, ctr: 1000 / impressions };
});
for (const horizon of [7, 14, 28]) {
    const result = forecastSearch(constantClicks, horizon);
    assert.equal(result.clicks.pipelineAccepted, false, 'A worse compound forecast cannot replace the click baseline');
    assert.ok(result.clicks.pipelineMae > result.clicks.baselineMae);
    assert.equal(result.clicks.mae, 0);
    assert.ok(result.clicks.points.every(point => point.value === 10));
}
assert.deepEqual(forecastMetric(history.slice(0, 13), 'clicks', 14).points, []);
assert.equal(forecastMetric(history.slice(0, 20), 'clicks', 7).mae, null);
assert.deepEqual(forecastMetric(history.filter((_, i) => i % 2 === 0), 'clicks', 14).points, []);
assert.deepEqual(forecastSearch(history, 14, date(190)).clicks.points, []);
assert.deepEqual(forecastMetric(history, 'clicks', 99).points, []);
assert.deepEqual(forecastMetric(history.map(row => ({ ...row, position: null })), 'position', 7).points, []);
const noLast = history.map((row, index) => ({ ...row, position: index === 179 ? null : row.position }));
assert.deepEqual(forecastMetric(noLast, 'position', 7).points, []);
const zeroLast = history.map((row, index) => index === 179 ? { ...row, impressions: 0, clicks: 0, ctr: 0 } : row);
assert.deepEqual(forecastSearch(zeroLast, 14).clicks.points, []);
const known = forecastMetric(history.slice(0, 150), 'impressions', 14, date(149));
const leaked = forecastMetric([...history.slice(0, 150), ...history.slice(150).map(row => ({ ...row, impressions: 999999 }))], 'impressions', 14, date(149));
assert.deepEqual(known, leaked, 'Future observations excluded by frozen as-of date');
assert.deepEqual(forecastMetric([...history].reverse(), 'impressions', 14), forecastMetric(history, 'impressions', 14));
assert.deepEqual(forecastSearch([...history].reverse(), 7), forecastSearch(history, 7), 'Entire pipeline normalizes unordered observations');
const noisy = history.map((row, index) => ({ ...row, impressions: row.impressions + (index * 37 % 23) * 4, position: 4 + (index * 17 % 13), ctr: 3 + (index * 31 % 9) / 10 }));
const extra = forecastMetric(noisy, 'ctr', 14);
assert.ok(extra.inputTest.folds >= 4, 'Additional position input evaluated on shared origins');
assert.equal(extra.inputTest.accepted, false, 'Unhelpful position input rejected');
const helpful = Array.from({ length: 180 }, (_, index) => {
    const position = 8 + 4 * Math.sin(index / 30);
    return { date: date(index), position, ctr: 15 - .8 * position + (index * 31 % 11 - 5) * .15, impressions: 1000 };
});
const accepted = forecastMetric(helpful, 'ctr', 14);
assert.equal(accepted.inputTest.accepted, true, 'Helpful position input adopted after shared historical tests');
assert.ok(accepted.inputTest.improvement >= .05);
assert.equal(accepted.method, 'Position-assisted CTR');
const demandHelpful = helpful.map((row, index) => ({ ...row, position: 8, impressions: Math.exp(8 + 4 * Math.sin(index / 30)) }));
const demandAccepted = forecastMetric(demandHelpful, 'ctr', 14);
assert.equal(demandAccepted.inputTests[1].accepted, true, 'Impression input tested and adopted only when it lowers historical error');
assert.equal(demandAccepted.method, 'Impression-assisted CTR');
assert.deepEqual(forecastMetric([...helpful, { date: '2026-02-30', ctr: 99, impressions: 1000, position: 1 }], 'ctr', 14), accepted, 'Invalid calendar dates excluded');
for (const [key, result] of Object.entries(forecastSearch(noisy, 28))) for (const point of result.points) {
    assert.ok(Number.isFinite(point.value));
    assert.ok(point.value >= (key === 'position' ? 1 : 0));
    if (key === 'ctr') assert.ok(point.value <= 100);
}
// Daily error band: starts on the last recorded value, never narrows, ends on the tile's range basis.
for (const [key, result] of Object.entries(forecastSearch(noisy, 14))) {
    if (!result.band?.length) continue;
    assert.equal(result.band.length, result.points.length, key + ' band covers every projected day');
    assert.equal(result.band[0].lower, result.points[0].value);
    assert.equal(result.band[0].upper, result.points[0].value);
    for (let index = 1; index < result.band.length; index++) {
        const point = result.points[index], band = result.band[index];
        assert.ok(band.lower <= point.value && point.value <= band.upper, key + ' projection inside its band');
        assert.ok(band.lower >= (key === 'position' ? 1 : 0), key + ' band respects the metric floor');
    }
}
assert.deepEqual(forecastMetric(noisy.slice(0, 20), 'clicks', 14).band ?? [], [], 'No band without eight backtests per day');
console.log('Forecast checks passed: weekday improvement, rolling tests, coherent clicks, envelopes, short/sparse/stale/empty history, frozen date leakage and rejected input.');
