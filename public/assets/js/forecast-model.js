// Pure, bounded forecasting over persisted daily observations. No provider calls.
const DAY = 86400000;
const stamp = date => Date.parse(date + 'T00:00:00Z');
const mean = values => values.reduce((sum, value) => sum + value, 0) / values.length;
const clamp = (key, value) => Math.max(key === 'position' ? 1 : 0, key === 'ctr' ? Math.min(100, value) : value);
const names = { last: 'Last observation', linear: '28-day linear trend', mean: '28-day average', weekday: 'Weekday average', seasonal: 'Weekday + damped trend', position: 'Position-assisted CTR', demand: 'Impression-assisted CTR' };
const windowRows = (rows, days) => rows.filter(row => stamp(row.date) >= stamp(rows.at(-1).date) - (days - 1) * DAY);
const normalize = (input, asOf) => [...new Map(input.filter(row => /^\d{4}-\d{2}-\d{2}$/.test(row.date) && Number.isFinite(stamp(row.date)) && new Date(stamp(row.date)).toISOString().slice(0, 10) === row.date && (!asOf || row.date <= asOf)).map(row => [row.date, row])).values()].sort((a, b) => a.date.localeCompare(b.date));

function trend(rows, key) {
    const end = stamp(rows.at(-1).date);
    const points = rows.map(row => [(stamp(row.date) - end) / DAY, row[key]]);
    const x = mean(points.map(p => p[0])), y = mean(points.map(p => p[1]));
    const denominator = points.reduce((sum, p) => sum + (p[0] - x) ** 2, 0);
    const slope = denominator ? points.reduce((sum, p) => sum + (p[0] - x) * (p[1] - y), 0) / denominator : 0;
    return { slope, level: y - slope * x };
}

function fit(rows, key, method) {
    const recent = windowRows(rows, method === 'seasonal' ? 84 : ['weekday', 'position', 'demand'].includes(method) ? 56 : 28);
    if (recent.length < 14 || (stamp(recent.at(-1).date) - stamp(recent[0].date)) / DAY < 13) return null;
    if (recent.length / ((stamp(recent.at(-1).date) - stamp(recent[0].date)) / DAY + 1) < .8) return null;
    const last = recent.at(-1), line = trend(recent, key);
    if (method === 'last') return () => last[key];
    if (method === 'mean') return () => mean(recent.map(row => row[key]));
    if (method === 'linear') return offset => last[key] + line.slope * offset;
    if (method === 'position' || method === 'demand') {
        const pairs = recent.filter(row => row.impressions > 0 && (method === 'demand' || Number.isFinite(row.position) && row.position >= 1)).map(row => ({ ...row, predictor: method === 'position' ? row.position : Math.log(row.impressions) }));
        if (key !== 'ctr' || pairs.length < 42) return null;
        const x = mean(pairs.map(row => row.predictor)), y = mean(pairs.map(row => row.ctr));
        const variance = pairs.reduce((sum, row) => sum + (row.predictor - x) ** 2, 0);
        if (variance < (method === 'position' ? 1 : .01)) return null;
        const slope = pairs.reduce((sum, row) => sum + (row.predictor - x) * (row.ctr - y), 0) / variance;
        const inputLine = trend(windowRows(pairs, 28), 'predictor');
        // Future inputs are forecast from training data, never read from the holdout.
        return offset => y + slope * (Math.max(method === 'position' ? 1 : 0, inputLine.level + inputLine.slope * 14 * (1 - Math.exp(-offset / 14))) - x);
    }
    const groups = Array.from({ length: 7 }, () => []);
    for (const row of recent) groups[new Date(stamp(row.date)).getUTCDay()].push(row);
    if (groups.some(group => group.length < 4)) return null;
    if (method === 'weekday') return offset => mean(groups[new Date(stamp(last.date) + offset * DAY).getUTCDay()].map(row => row[key]));
    const residuals = groups.map(group => mean(group.map(row => row[key] - line.level - line.slope * (stamp(row.date) - stamp(last.date)) / DAY)));
    return offset => line.level + line.slope * 14 * (1 - Math.exp(-offset / 14)) + residuals[new Date(stamp(last.date) + offset * DAY).getUTCDay()];
}

// Daily error band: per forecast day, the 80th percentile of that day's backtest errors (at least
// eight), never narrower than an earlier day. A historical envelope, not a probability interval.
function band(steps, points, key) {
    const radii = [];
    for (let step = 1; step < points.length; step++) {
        const sorted = [...(steps[step] ?? [])].sort((a, b) => a - b);
        if (sorted.length < 8) return [];
        radii.push(Math.max(radii.at(-1) ?? 0, sorted[Math.ceil(sorted.length * .8) - 1]));
    }
    return points.map((point, index) => index === 0 ? { date: point.date, lower: point.value, upper: point.value } : { date: point.date, lower: clamp(key, point.value - radii[index - 1]), upper: clamp(key, point.value + radii[index - 1]) });
}
const byStep = (steps, step, error) => (steps[step] ??= []).push(error);

function evaluate(rows, key, horizon, method, allowedOrigins = null) {
    const errors = [], endpoints = [], origins = [], steps = {};
    const byDate = new Map(rows.map(row => [row.date, row]));
    const last = stamp(rows.at(-1).date);
    // Weekly origins, at most twelve folds. Training is strictly earlier than each target.
    for (let back = horizon; back <= horizon + 77; back += 7) {
        const origin = last - back * DAY;
        if (allowedOrigins && !allowedOrigins.includes(origin)) continue;
        const training = rows.filter(row => stamp(row.date) <= origin);
        if (!training.length || stamp(training.at(-1).date) !== origin) continue;
        const model = fit(training, key, method);
        if (!model) continue;
        const fold = [];
        for (let step = 1; step <= horizon; step++) {
            const date = new Date(origin + step * DAY).toISOString().slice(0, 10);
            const actual = byDate.get(date);
            if (actual) fold.push({ step, error: Math.abs(clamp(key, model(step)) - actual[key]) });
        }
        if (fold.length < Math.ceil(horizon * .8)) continue;
        origins.push(origin);
        errors.push(...fold.map(point => point.error));
        fold.forEach(point => byStep(steps, point.step, point.error));
        const endpoint = fold.find(point => point.step === horizon);
        if (endpoint) endpoints.push(endpoint.error);
    }
    return { method, name: names[method], folds: origins.length, origins, sample: errors.length, mae: errors.length ? mean(errors) : null, endpoints, steps };
}

export function forecastMetric(input, key, horizon, asOf) {
    if (![7, 14, 28].includes(horizon)) return { points: [], candidates: [], reason: 'Unsupported horizon' };
    const dated = normalize(input, asOf);
    if (!dated.length || !Number.isFinite(dated.at(-1)[key]) || (key === 'ctr' && !(dated.at(-1).impressions > 0))) return { points: [], candidates: [], reason: 'Latest observation unavailable for this metric' };
    const rows = [...new Map(dated.filter(row => Number.isFinite(row[key]) && row[key] >= 0 && (key !== 'ctr' || row.impressions > 0)).map(row => [row.date, row])).values()];
    if (!rows.length) return { points: [], candidates: [], reason: 'No eligible history' };
    const last = rows.at(-1);
    const elapsed = asOf ? (stamp(asOf) - stamp(last.date)) / DAY : 0;
    if (elapsed > 7) return { points: [], candidates: [], reason: 'Latest observation is over seven days old' };
    const history = windowRows(rows, 180);
    const methods = ['last', 'linear', 'mean', 'weekday', 'seasonal'];
    const candidates = methods.filter(method => fit(history, key, method)).map(method => evaluate(history, key, horizon, method));
    if (!candidates.length) return { points: [], candidates: [], reason: 'Needs 14 observations and at least 80% date coverage' };
    // Compare on identical folds: a method must not win by skipping difficult dates.
    const eligible = candidates.filter(candidate => candidate.folds >= 4);
    const common = eligible.length ? eligible.reduce((set, candidate) => set.filter(origin => candidate.origins.includes(origin)), eligible[0].origins) : [];
    const comparable = common.length >= 4 ? eligible.map(candidate => {
        const subset = history;
        // Re-evaluate each origin with exactly the same target dates.
        const errors = [], endpoints = [], steps = {};
        for (const origin of common) {
            const training = subset.filter(row => stamp(row.date) <= origin);
            const model = fit(training, key, candidate.method);
            for (const actual of subset.filter(row => stamp(row.date) > origin && stamp(row.date) <= origin + horizon * DAY)) {
                const step = (stamp(actual.date) - origin) / DAY;
                const error = Math.abs(clamp(key, model(step)) - actual[key]);
                errors.push(error);
                byStep(steps, step, error);
                if (step === horizon) endpoints.push(error);
            }
        }
        return { ...candidate, origins: common, folds: common.length, sample: errors.length, mae: mean(errors), endpoints, steps };
    }) : [];
    let selected = comparable.length ? [...comparable].sort((a, b) => a.mae - b.mae)[0] : candidates.find(candidate => candidate.method === 'mean');
    const inputTests = [];
    const inputCandidates = key === 'ctr' ? ['position', 'demand'].filter(method => fit(history, key, method)).map(method => evaluate(history, key, horizon, method)).filter(candidate => candidate.folds >= 4) : [];
    const sharedInputOrigins = selected && selected.folds >= 4 ? inputCandidates.reduce((origins, candidate) => origins.filter(origin => candidate.origins.includes(origin)), selected.origins) : [];
    let winningInput = null;
    for (const method of key === 'ctr' ? ['position', 'demand'] : []) {
        const additional = inputCandidates.find(candidate => candidate.method === method);
        const inputOrigins = additional ? sharedInputOrigins : [];
        if (inputOrigins.length < 4) { inputTests.push({ name: names[method], status: 'Insufficient paired history', accepted: false }); continue; }
        const baselineTest = evaluate(history, key, horizon, selected.method, inputOrigins);
        const inputScore = evaluate(history, key, horizon, method, inputOrigins);
        const improvement = baselineTest.mae > 0 ? (baselineTest.mae - inputScore.mae) / baselineTest.mae : 0;
        inputTests.push({ name: names[method], status: improvement >= .05 ? 'Improved backtests by at least 5%' : 'Did not improve backtests by 5%', accepted: improvement >= .05, baselineMae: baselineTest.mae, inputMae: inputScore.mae, folds: inputScore.folds, improvement });
        if (improvement >= .05 && (!winningInput || improvement > winningInput.improvement)) winningInput = { ...inputScore, improvement };
    }
    if (winningInput) selected = winningInput;
    const inputTest = inputTests[0] || { status: 'Not applicable', accepted: false };
    if (!selected) return { points: [], candidates, reason: 'Insufficient coverage' };
    const model = fit(history, key, selected.method);
    const endpointErrors = [...selected.endpoints].sort((a, b) => a - b);
    const radius = endpointErrors.length >= 8 ? endpointErrors[Math.ceil(endpointErrors.length * .8) - 1] : null;
    const points = Array.from({ length: horizon + 1 }, (_, offset) => {
        const value = clamp(key, offset === 0 ? last[key] : model(offset));
        return { date: new Date(stamp(last.date) + offset * DAY).toISOString().slice(0, 10), value };
    });
    return { points, band: band(selected.steps ?? {}, points, key), candidates: comparable, method: selected.name, mae: comparable.length || winningInput ? selected.mae : null, folds: comparable.length || winningInput ? selected.folds : 0, sample: selected.sample, range: radius === null ? null : { lower: clamp(key, points.at(-1).value - radius), upper: clamp(key, points.at(-1).value + radius), sample: endpointErrors.length }, inputTest, inputTests, history: history.length, from: history[0].date, to: last.date, lag: elapsed };
}

export function forecastSearch(history, horizon, asOf) {
    history = normalize(history, asOf);
    if (history.length) history = windowRows(history, 180);
    const impressions = forecastMetric(history, 'impressions', horizon, asOf);
    const ctr = forecastMetric(history, 'ctr', horizon, asOf);
    const position = forecastMetric(history, 'position', horizon, asOf);
    const byDate = new Map(ctr.points.map(point => [point.date, point.value]));
    const aligned = ctr.points[0]?.date === impressions.points[0]?.date && ctr.points.length === horizon + 1 && impressions.points.length === horizon + 1;
    let points = aligned ? impressions.points.map(point => ({ date: point.date, value: point.value * byDate.get(point.date) / 100 })) : [];
    let errors = [], endpoints = [], steps = {};
    const baselineErrors = [], baselineEndpoints = [], baselineSteps = {};
    let folds = 0;
    if (points.length) {
        const end = stamp(points[0].date);
        for (let back = horizon; back <= horizon + 77; back += 7) {
            const origin = end - back * DAY;
            const training = history.filter(row => stamp(row.date) <= origin);
            if (!training.length || stamp(training.at(-1).date) !== origin) continue;
            const demand = forecastMetric(training, 'impressions', horizon);
            const rate = forecastMetric(training, 'ctr', horizon);
            const baseline = fit(training, 'clicks', 'linear');
            if (!baseline || !demand.points.length || !rate.points.length) continue;
            const actuals = history.filter(row => stamp(row.date) > origin && stamp(row.date) <= origin + horizon * DAY && Number.isFinite(row.clicks));
            if (actuals.length < Math.ceil(horizon * .8)) continue;
            folds++;
            for (const actual of actuals) {
                const step = (stamp(actual.date) - origin) / DAY;
                const error = Math.abs(demand.points[step].value * rate.points[step].value / 100 - actual.clicks);
                errors.push(error);
                byStep(steps, step, error);
                const baselineError = Math.abs(clamp('clicks', baseline(step)) - actual.clicks);
                baselineErrors.push(baselineError);
                byStep(baselineSteps, step, baselineError);
                if (step === horizon) baselineEndpoints.push(baselineError);
                if (step === horizon) endpoints.push(error);
            }
        }
    }
    const pipelineMae = folds >= 4 ? mean(errors) : null;
    const baselineMae = folds >= 4 ? mean(baselineErrors) : null;
    const pipelineAccepted = folds >= 4 && baselineMae > 0 && pipelineMae <= baselineMae * .95;
    let method = 'Impressions × CTR (unvalidated)';
    if (folds >= 4) {
        method = pipelineAccepted ? 'Impressions × CTR' : '28-day linear clicks (pipeline rejected)';
        if (!pipelineAccepted) {
            const baseline = fit(history, 'clicks', 'linear');
            points = points.map((point, step) => ({ ...point, value: step === 0 ? history.at(-1).clicks : clamp('clicks', baseline(step)) }));
            errors = baselineErrors;
            endpoints = baselineEndpoints;
            steps = baselineSteps;
        }
    }
    endpoints.sort((a, b) => a - b);
    const radius = endpoints.length >= 8 ? endpoints[Math.ceil(endpoints.length * .8) - 1] : null;
    return { impressions, ctr, position, clicks: { points, band: folds >= 4 ? band(steps, points, 'clicks') : [], method, range: radius === null ? null : { lower: Math.max(0, points.at(-1).value - radius), upper: points.at(-1).value + radius, sample: endpoints.length }, mae: folds >= 4 ? mean(errors) : null, baselineMae, pipelineMae, pipelineAccepted, sample: errors.length, folds: folds >= 4 ? folds : 0 } };
}
