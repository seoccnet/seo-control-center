import assert from 'node:assert/strict';
globalThis.document = { querySelectorAll: () => [] };
const { projectMetric, weekAverage, summarize, axisScale, plotY } = await import('../../public/assets/js/prognosis.js');
const day = (index, value) => ({ date: new Date(Date.UTC(2026, 8, 1 + index)).toISOString().slice(0, 10), clicks: value, impressions: 1000, ctr: value, position: value });
const linear = Array.from({ length: 28 }, (_, index) => day(index, 100 + index * 2));
assert.equal(projectMetric(linear, 'clicks', 14).at(-1).value, 127, 'Short history uses an unvalidated average rather than untested trend extrapolation');
assert.equal(projectMetric(linear, 'clicks', 14).at(-1).date, '2026-10-12');
assert.deepEqual(projectMetric(linear.slice(0, 13), 'clicks', 14), []);
assert.deepEqual(projectMetric([day(0, 100), day(80, 200)], 'clicks', 14), []);
assert.deepEqual(projectMetric(linear.map(row => ({ ...row, position: null })), 'position', 7), []);
assert.equal(projectMetric(linear, 'ctr', 7).at(-1).value, 100);
assert.equal(projectMetric(Array.from({ length: 28 }, (_, index) => day(index, 28 - index)), 'position', 28).at(-1).value, 14.5);
assert.equal(projectMetric(Array.from({ length: 28 }, (_, index) => day(index, 28 - index)), 'clicks', 28).at(-1).value, 14.5);
const sparse = Array.from({ length: 14 }, (_, index) => day(index * 2, 100 + index * 4));
assert.deepEqual(projectMetric(sparse, 'clicks', 7), [], 'Sparse coverage is withheld');
const noisy = linear.map((row, index) => ({ ...row, clicks: index === 27 ? 80 : row.clicks }));
const forecast = projectMetric(noisy, 'clicks', 14);
assert.equal(forecast[0].value, noisy.at(-1).clicks, 'Forecast joins the last recorded point even with a final-day dip');
assert.equal(forecast[0].date, noisy.at(-1).date);
assert.ok(forecast.every(point => Number.isFinite(point.value) && point.value >= 0));
assert.deepEqual(projectMetric(linear.map((row, index) => ({ ...row, position: index === 27 ? null : row.position })), 'position', 7), [], 'No projected position when the latest observation is unavailable');
console.log('Forecast renderer adapter checks passed: boundary, honest fallback, dates, sparse coverage, null positions and bounds.');

// 7-day average: trailing mean of the available days, withheld with fewer than five of seven.
const values = new Map([['2026-10-01', 10], ['2026-10-02', 20], ['2026-10-03', 30], ['2026-10-04', 40], ['2026-10-05', 50], ['2026-10-07', 60]]);
assert.deepEqual(weekAverage(values, ['2026-10-04', '2026-10-05', '2026-10-07']).map(point => point.value), [null, 30, 35]);
// Summary: compares the projected end with the last seven recorded days; position falls when it improves.
const week = Array.from({ length: 7 }, (_, index) => ({ date: `2026-10-0${index + 1}`, clicks: 20, impressions: 100, ctr: 2, position: 12 }));
const text = summarize(week, { clicks: [{ value: 20 }, { value: 30 }], impressions: [{ value: 101 }], ctr: [], position: [{ value: 9 }] }, 14);
assert.equal(text, 'In 14 days, if recent patterns continue: clicks about 30 a day (rising from 20), impressions about 101 a day (steady at 100), average position about 9 (improving from 12). Compared with the average of the last 7 recorded days; a projection, not a promise.');
assert.equal(summarize(week.slice(0, 3), { clicks: [{ value: 30 }] }, 7), '', 'Too few recent days: no summary');

// Axis: the position axis starts at its best value, 1, and ends 1 + four round steps above the peak.
assert.equal(axisScale('position', 18.88), 21);
assert.equal(axisScale('position', 3), 3);
assert.equal(axisScale('clicks', 38), 40);
assert.equal(plotY({ key: 'position' }, 1, 21), 18, 'Position 1 is the top of the plot');
assert.equal(plotY({ key: 'position' }, 21, 21), 248, 'The axis end is the bottom');
assert.equal(plotY({ key: 'clicks' }, 0, 40), 248);
