// Safe Markdown for the AI chat (assistant.js). The answer is untrusted model output: every piece of
// text is HTML-escaped before markup is added, links are limited to http(s) and same-site paths, and
// charts are drawn from validated numbers only. Supported: headings, paragraphs, bold/italic/strike,
// inline code, code blocks, lists (one nesting level), block quotes, rules, tables and ```chart blocks.

export const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const numberFormat = new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 });
const compactFormat = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });

export const formatNumber = (value, compact = false) => (compact && Math.abs(value) >= 10000 ? compactFormat : numberFormat).format(value);

export function inline(source) {
    const codes = [];
    let text = String(source).replace(/\u0000/g, '').replace(/`([^`\n]+)`/g, (match, code) => {
        codes.push(code);
        return '\u0000' + (codes.length - 1) + '\u0000';
    });
    text = escapeHtml(text)
        .replace(/\*\*(?=\S)(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/__(?=\S)(.+?)__/g, '<strong>$1</strong>')
        .replace(/(^|[^*\w])\*(?=\S)([^*\n]+?)\*(?!\w)/g, '$1<em>$2</em>')
        .replace(/~~(?=\S)(.+?)~~/g, '<del>$1</del>')
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/(?!\/)[^\s)]*)\)/g, (match, label, href) => '<a href="' + href + '"' + (href.startsWith('/') ? '' : ' rel="noopener noreferrer" target="_blank"') + '>' + label + '</a>');

    return text.replace(/\u0000(\d+)\u0000/g, (match, index) => '<code>' + escapeHtml(codes[Number(index)]) + '</code>');
}

// ---- Tables -------------------------------------------------------------------------------------

const isTableRow = (line) => /^\s*\|.*\|\s*$/.test(line);
const isSeparator = (line) => /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/.test(line);

export function splitRow(line) {
    const cells = [];
    let cell = '';
    const body = line.trim().replace(/^\|/, '').replace(/\|$/, '');
    for (let i = 0; i < body.length; i++) {
        if (body[i] === '\\' && body[i + 1] === '|') {
            cell += '|';
            i++;
        } else if (body[i] === '|') {
            cells.push(cell.trim());
            cell = '';
        } else {
            cell += body[i];
        }
    }
    cells.push(cell.trim());
    return cells;
}

// Plain cell text without Markdown markers, for copying and CSV.
export const plainCell = (text) => String(text).replace(/\*\*|__|~~|`/g, '').replace(/\[([^\]]+)\]\([^)]+\)/g, '$1').trim();

const NUMERIC = /^[-+−]?[$€£]?\s?\d[\d.,\s]*\s?(%|pp|k|K|M|ms|s)?$/;

function table(header, align, rows) {
    const width = header.length;
    const numeric = header.map((_, column) => rows.length > 0 && rows.every((row) => {
        const cell = plainCell(row[column] ?? '');
        return cell === '' || cell === '–' || cell === '-' || NUMERIC.test(cell);
    }));
    const cellClass = (column) => {
        const side = align[column] || (numeric[column] ? 'right' : '');
        return side === 'right' ? ' class="num"' : side === 'center' ? ' class="center"' : '';
    };
    const head = header.map((cell, column) => '<th scope="col"' + cellClass(column) + '>' + inline(cell) + '</th>').join('');
    const body = rows.map((row) => '<tr>' + Array.from({ length: width }, (_, column) => '<td' + cellClass(column) + '>' + inline(row[column] ?? '') + '</td>').join('') + '</tr>').join('');

    return '<div class="assistant-block assistant-table" data-assistant-table>'
        + '<div class="assistant-block-tools"><span>' + rows.length + (rows.length === 1 ? ' row' : ' rows') + '</span>'
        + '<button type="button" data-assistant-copy-table>Copy table</button>'
        + '<button type="button" data-assistant-csv>Download CSV</button></div>'
        + '<div class="assistant-table-scroll"><table><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div></div>';
}

// Rows of a rendered table as plain text, header first.
export const csvField = (value) => (/[",\n;]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value);
export const toCsv = (rows) => rows.map((row) => row.map((cell) => csvField(String(cell))).join(',')).join('\r\n') + '\r\n';
export const toTsv = (rows) => rows.map((row) => row.map((cell) => String(cell).replace(/[\t\n]+/g, ' ')).join('\t')).join('\n');

// ---- Charts -------------------------------------------------------------------------------------

const LIMITS = { line: { labels: 120, series: 3 }, bar: { labels: 20, series: 2 } };

export function chartSpec(json) {
    let spec;
    try {
        spec = JSON.parse(json);
    } catch {
        return null;
    }
    if (!spec || typeof spec !== 'object' || !['line', 'bar'].includes(spec.type) || !Array.isArray(spec.labels) || !Array.isArray(spec.series)) {
        return null;
    }
    const limit = LIMITS[spec.type];
    const labels = spec.labels.map((label) => String(label).slice(0, 80));
    if (labels.length < (spec.type === 'line' ? 2 : 1) || labels.length > limit.labels || spec.series.length < 1 || spec.series.length > limit.series) {
        return null;
    }
    const series = [];
    for (const item of spec.series) {
        if (!item || typeof item !== 'object' || !Array.isArray(item.values) || item.values.length !== labels.length) {
            return null;
        }
        const values = item.values.map((value) => (value === null ? null : Number(value)));
        if (values.some((value) => value !== null && !Number.isFinite(value)) || values.every((value) => value === null)) {
            return null;
        }
        series.push({ name: String(item.name ?? 'Value').slice(0, 60), values });
    }
    return {
        type: spec.type,
        title: typeof spec.title === 'string' ? spec.title.slice(0, 120) : '',
        unit: typeof spec.unit === 'string' ? spec.unit.slice(0, 8) : '',
        labels,
        series,
    };
}

const niceStep = (span) => {
    const raw = span / 4;
    const power = 10 ** Math.floor(Math.log10(raw || 1));
    return [1, 2, 2.5, 5, 10].map((m) => m * power).find((step) => step >= raw) || raw;
};

const truncate = (text, max) => (text.length > max ? text.slice(0, max - 1) + '…' : text);

function legend(series, width) {
    if (series.length < 2) {
        return '';
    }
    let x = width;
    const items = [];
    for (let i = series.length - 1; i >= 0; i--) {
        const name = truncate(series[i].name, 24);
        x -= name.length * 6.4 + 22;
        items.unshift('<rect class="chart-series-' + i + '" x="' + x.toFixed(1) + '" y="4" width="10" height="10" rx="2"/><text class="chart-legend" x="' + (x + 14).toFixed(1) + '" y="13">' + escapeHtml(name) + '</text>');
    }
    return items.join('');
}

function lineChart(spec) {
    const width = 640;
    const height = 280;
    const pad = { left: 52, right: 16, top: spec.series.length > 1 ? 28 : 14, bottom: 34 };
    const values = spec.series.flatMap((s) => s.values).filter((v) => v !== null);
    let min = Math.min(...values);
    let max = Math.max(...values);
    if (min > 0 && min / (max || 1) < 0.5) {
        min = 0;
    }
    if (min === max) {
        min -= 1;
        max += 1;
    }
    const step = niceStep(max - min);
    min = Math.floor(min / step) * step;
    max = Math.ceil(max / step) * step;
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;
    const x = (i) => pad.left + (spec.labels.length === 1 ? plotW / 2 : (i / (spec.labels.length - 1)) * plotW);
    const y = (v) => pad.top + plotH - ((v - min) / (max - min)) * plotH;
    const parts = [];
    for (let v = min; v <= max + step / 2; v += step) {
        parts.push('<line class="chart-grid" x1="' + pad.left + '" x2="' + (width - pad.right) + '" y1="' + y(v).toFixed(1) + '" y2="' + y(v).toFixed(1) + '"/>');
        parts.push('<text class="chart-axis" x="' + (pad.left - 8) + '" y="' + (y(v) + 4).toFixed(1) + '" text-anchor="end">' + escapeHtml(formatNumber(Number(v.toFixed(6)), true) + spec.unit) + '</text>');
    }
    const ticks = spec.labels.length <= 6 ? spec.labels.map((_, i) => i) : [0, Math.round((spec.labels.length - 1) / 3), Math.round((2 * (spec.labels.length - 1)) / 3), spec.labels.length - 1];
    for (const i of ticks) {
        const anchor = i === 0 ? 'start' : i === spec.labels.length - 1 ? 'end' : 'middle';
        parts.push('<text class="chart-axis" x="' + x(i).toFixed(1) + '" y="' + (height - 12) + '" text-anchor="' + anchor + '">' + escapeHtml(truncate(spec.labels[i], 16)) + '</text>');
    }
    spec.series.forEach((series, index) => {
        let path = '';
        series.values.forEach((v, i) => {
            if (v !== null) {
                path += (path === '' || series.values[i - 1] === null ? 'M' : 'L') + x(i).toFixed(1) + ' ' + y(v).toFixed(1) + ' ';
            }
        });
        parts.push('<path class="chart-line chart-series-' + index + '" d="' + path.trim() + '"/>');
        if (spec.labels.length <= 31) {
            series.values.forEach((v, i) => {
                if (v !== null) {
                    parts.push('<circle class="chart-dot chart-series-' + index + '" cx="' + x(i).toFixed(1) + '" cy="' + y(v).toFixed(1) + '" r="2.5"><title>' + escapeHtml(spec.labels[i] + ' · ' + series.name + ': ' + formatNumber(v) + spec.unit) + '</title></circle>');
                }
            });
        }
    });
    return { width, height, body: legend(spec.series, width - pad.right) + parts.join('') };
}

function barChart(spec) {
    const width = 640;
    const rowH = spec.series.length > 1 ? 34 : 26;
    const top = spec.series.length > 1 ? 26 : 6;
    const height = top + spec.labels.length * rowH + 8;
    const labelW = 200;
    const valueW = 64;
    const values = spec.series.flatMap((s) => s.values).filter((v) => v !== null);
    const min = Math.min(0, ...values);
    const max = Math.max(0, ...values);
    const span = max - min || 1;
    const plotW = width - labelW - valueW;
    const x = (v) => labelW + ((v - min) / span) * plotW;
    const parts = [];
    if (min < 0) {
        parts.push('<line class="chart-zero" x1="' + x(0).toFixed(1) + '" x2="' + x(0).toFixed(1) + '" y1="' + top + '" y2="' + (height - 8) + '"/>');
    }
    const barH = spec.series.length > 1 ? 12 : 16;
    spec.labels.forEach((label, row) => {
        const rowTop = top + row * rowH;
        parts.push('<text class="chart-label" x="' + (labelW - 10) + '" y="' + (rowTop + rowH / 2 + 4).toFixed(1) + '" text-anchor="end">' + escapeHtml(truncate(label, 30)) + '<title>' + escapeHtml(label) + '</title></text>');
        spec.series.forEach((series, index) => {
            const v = series.values[row];
            if (v === null) {
                return;
            }
            const y = rowTop + (rowH - barH * spec.series.length - (spec.series.length - 1) * 2) / 2 + index * (barH + 2);
            const start = Math.min(x(0), x(v));
            const w = Math.max(1, Math.abs(x(v) - x(0)));
            parts.push('<rect class="chart-bar chart-series-' + index + (v < 0 ? ' chart-negative' : '') + '" x="' + start.toFixed(1) + '" y="' + y.toFixed(1) + '" width="' + w.toFixed(1) + '" height="' + barH + '" rx="3"><title>' + escapeHtml(label + ' · ' + series.name + ': ' + formatNumber(v) + spec.unit) + '</title></rect>');
            const after = v < 0 ? start - 6 : start + w + 6;
            parts.push('<text class="chart-value" x="' + after.toFixed(1) + '" y="' + (y + barH - 3).toFixed(1) + '" text-anchor="' + (v < 0 ? 'end' : 'start') + '">' + escapeHtml(formatNumber(v, true) + spec.unit) + '</text>');
        });
    });
    return { width, height, body: legend(spec.series, width - 8) + parts.join('') };
}

export function chartSvg(spec) {
    const { width, height, body } = spec.type === 'line' ? lineChart(spec) : barChart(spec);
    const label = (spec.title || 'Chart') + ': ' + spec.series.map((s) => s.name).join(', ');
    return '<svg class="assistant-chart-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + width + ' ' + height + '" width="' + width + '" height="' + height + '" role="img" aria-label="' + escapeHtml(label) + '"><title>' + escapeHtml(label) + '</title>' + body + '</svg>';
}

export const chartRows = (spec) => [['', ...spec.series.map((s) => s.name)], ...spec.labels.map((label, i) => [label, ...spec.series.map((s) => (s.values[i] === null ? '' : String(s.values[i])))])];

function chart(json, raw) {
    const spec = chartSpec(json);
    if (spec === null) {
        return '<pre><code>' + escapeHtml(raw) + '</code></pre>';
    }
    return '<figure class="assistant-block assistant-chart" data-assistant-chart="' + escapeHtml(JSON.stringify(spec)) + '">'
        + '<div class="assistant-block-tools"><span>' + escapeHtml(spec.title || 'Chart') + '</span>'
        + '<button type="button" data-assistant-chart-copy>Copy data</button>'
        + '<button type="button" data-assistant-chart-svg>Download SVG</button></div>'
        + '<div class="assistant-chart-scroll">' + chartSvg(spec) + '</div></figure>';
}

// ---- Blocks -------------------------------------------------------------------------------------

export function renderMarkdown(source) {
    const lines = String(source).replace(/\r\n?/g, '\n').split('\n');
    const html = [];
    let paragraph = [];
    let list = null;

    const flushParagraph = () => {
        if (paragraph.length) {
            html.push('<p>' + paragraph.map(inline).join('<br>') + '</p>');
            paragraph = [];
        }
    };
    const flushList = () => {
        if (list) {
            html.push('<' + list.type + '>' + list.items.map((item) => '<li>' + inline(item.text) + (item.children.length ? '<' + item.childType + '>' + item.children.map((child) => '<li>' + inline(child) + '</li>').join('') + '</' + item.childType + '>' : '') + '</li>').join('') + '</' + list.type + '>');
            list = null;
        }
    };
    const flush = () => {
        flushParagraph();
        flushList();
    };

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const fence = line.match(/^\s*```\s*([\w-]*)\s*$/);
        if (fence) {
            flush();
            const body = [];
            for (i++; i < lines.length && !/^\s*```\s*$/.test(lines[i]); i++) {
                body.push(lines[i]);
            }
            const code = body.join('\n');
            html.push(fence[1].toLowerCase() === 'chart' ? chart(code, code) : '<pre><code>' + escapeHtml(code) + '</code></pre>');
            continue;
        }
        if (isTableRow(line) && i + 1 < lines.length && isSeparator(lines[i + 1])) {
            flush();
            const header = splitRow(line);
            const align = splitRow(lines[i + 1]).map((cell) => (cell.startsWith(':') && cell.endsWith(':') ? 'center' : cell.endsWith(':') ? 'right' : ''));
            const rows = [];
            for (i += 2; i < lines.length && isTableRow(lines[i]); i++) {
                rows.push(splitRow(lines[i]));
            }
            i--;
            html.push(table(header, align, rows));
            continue;
        }
        const heading = line.match(/^\s*(#{1,6})\s+(.*?)\s*#*\s*$/);
        if (heading) {
            flush();
            const level = Math.min(6, heading[1].length + 2);
            html.push('<h' + level + ' class="assistant-heading">' + inline(heading[2]) + '</h' + level + '>');
            continue;
        }
        if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) {
            flush();
            html.push('<hr>');
            continue;
        }
        const quote = line.match(/^\s*>\s?(.*)$/);
        if (quote) {
            flush();
            const body = [quote[1]];
            while (i + 1 < lines.length && /^\s*>/.test(lines[i + 1])) {
                body.push(lines[++i].replace(/^\s*>\s?/, ''));
            }
            html.push('<blockquote>' + body.filter((l) => l.trim() !== '').map((l) => '<p>' + inline(l) + '</p>').join('') + '</blockquote>');
            continue;
        }
        const item = line.match(/^(\s*)([-*+•]|\d+[.)])\s+(.*)$/);
        if (item) {
            flushParagraph();
            const type = /\d/.test(item[2]) ? 'ol' : 'ul';
            if (item[1].length >= 2 && list && list.items.length) {
                const parent = list.items[list.items.length - 1];
                parent.childType = parent.children.length ? parent.childType : type;
                parent.children.push(item[3]);
                continue;
            }
            if (list && list.type !== type) {
                flushList();
            }
            list = list || { type, items: [] };
            list.items.push({ text: item[3], children: [], childType: 'ul' });
            continue;
        }
        if (line.trim() === '') {
            flush();
            continue;
        }
        if (list && /^\s{2,}\S/.test(line)) {
            // Continuation of the last list item.
            list.items[list.items.length - 1].text += ' ' + line.trim();
            continue;
        }
        flushList();
        paragraph.push(line.trim());
    }
    flush();
    return html.join('');
}
