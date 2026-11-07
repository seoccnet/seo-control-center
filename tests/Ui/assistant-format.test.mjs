import assert from 'node:assert/strict';
const { renderMarkdown, inline, chartSpec, chartSvg, chartRows, toCsv, toTsv, splitRow, plainCell } = await import('../../public/assets/js/assistant-format.js');

// Untrusted text is escaped before any markup is added.
assert.equal(inline('<img src=x onerror=alert(1)>'), '&lt;img src=x onerror=alert(1)&gt;');
assert.equal(inline('[x](javascript:alert(1))'), '[x](javascript:alert(1))', 'Only http(s) and same-site links');
assert.equal(inline('[x](//evil.test/a)'), '[x](//evil.test/a)', 'Protocol-relative links are not same-site');
assert.equal(inline('[Pages](/projects/a/pages)'), '<a href="/projects/a/pages">Pages</a>');
assert.match(inline('[Docs](https://example.test/?a=1&b="2")'), /^<a href="https:\/\/example\.test\/\?a=1&amp;b=&quot;2&quot;" rel="noopener noreferrer" target="_blank">Docs<\/a>$/);
assert.equal(inline('**bold** and *em* and `<code>` and ~~old~~'), '<strong>bold</strong> and <em>em</em> and <code>&lt;code&gt;</code> and <del>old</del>');
assert.equal(inline('2 * 3 * 4'), '2 * 3 * 4', 'Spaced asterisks are not emphasis');
assert.equal(inline('a\u00000\u0000b'), 'a0b', 'Placeholders cannot be injected');

// Headings, lists, quotes and code blocks.
const blocks = renderMarkdown('## Summary\nLine one\nLine two\n\n- a\n  - nested\n- b\n\n1. first\n2. second\n\n> quoted\n\n```\n<b>raw</b>\n```\n\n---');
assert.ok(blocks.includes('<h4 class="assistant-heading">Summary</h4>'));
assert.ok(blocks.includes('<p>Line one<br>Line two</p>'));
assert.ok(blocks.includes('<ul><li>a<ul><li>nested</li></ul></li><li>b</li></ul>'));
assert.ok(blocks.includes('<ol><li>first</li><li>second</li></ol>'));
assert.ok(blocks.includes('<blockquote><p>quoted</p></blockquote>'));
assert.ok(blocks.includes('<pre><code>&lt;b&gt;raw&lt;/b&gt;</code></pre>'));
assert.ok(blocks.endsWith('<hr>'));

// Tables: header cells, numeric columns aligned right, escaped pipes, copy/CSV tools.
const tableHtml = renderMarkdown('| Query | Clicks | CTR |\n|---|---:|---|\n| how to \\| fix | 1,204 | 2.3 % |\n| **seo** tools | 12 | 0.4% |');
assert.ok(tableHtml.includes('<th scope="col">Query</th><th scope="col" class="num">Clicks</th><th scope="col" class="num">CTR</th>'));
assert.ok(tableHtml.includes('<td>how to | fix</td>'));
assert.ok(tableHtml.includes('<td><strong>seo</strong> tools</td>'));
assert.ok(tableHtml.includes('data-assistant-copy-table') && tableHtml.includes('data-assistant-csv') && tableHtml.includes('2 rows'));
assert.deepEqual(splitRow('| a | b \\| c |'), ['a', 'b | c']);
assert.equal(plainCell('**[Pricing](/pricing)**'), 'Pricing');
assert.equal(toCsv([['Query', 'Clicks'], ['a, "b"', '3']]), 'Query,Clicks\r\n"a, ""b""",3\r\n');
assert.equal(toTsv([['a\tb', 'c\nd']]), 'a b\tc d');
assert.ok(!renderMarkdown('| not | a table |\nplain').includes('<table>'), 'A table needs a separator row');

// Charts: validated JSON becomes SVG; anything else stays a code block.
const line = chartSpec(JSON.stringify({ type: 'line', title: 'Clicks', labels: ['2026-09-01', '2026-09-02', '2026-09-03'], series: [{ name: 'Clicks', values: [10, null, 30] }, { name: 'Impr.', values: [100, 120, 90] }] }));
assert.equal(line.series.length, 2);
const svg = chartSvg(line);
assert.ok(svg.startsWith('<svg class="assistant-chart-svg"') && svg.includes('role="img"'));
assert.equal((svg.match(/<path /g) || []).length, 2);
assert.ok(svg.includes('M') && !svg.includes('NaN'));
assert.deepEqual(chartRows(line)[0], ['', 'Clicks', 'Impr.']);
assert.deepEqual(chartRows(line)[2], ['2026-09-02', '', '120']);
const bars = chartSpec(JSON.stringify({ type: 'bar', labels: ['<script>', 'b'], series: [{ name: 'Change', values: [-5, 12] }], unit: '%' }));
const barSvg = chartSvg(bars);
assert.ok(barSvg.includes('&lt;script&gt;') && !barSvg.includes('<script>'));
assert.ok(barSvg.includes('chart-negative') && barSvg.includes('chart-zero'));
for (const bad of ['{', '{"type":"pie","labels":["a"],"series":[{"values":[1]}]}', '{"type":"bar","labels":["a","b"],"series":[{"values":[1]}]}', '{"type":"bar","labels":["a"],"series":[{"values":["x"]}]}', JSON.stringify({ type: 'bar', labels: Array(21).fill('a'), series: [{ values: Array(21).fill(1) }] })]) {
    assert.equal(chartSpec(bad), null, bad.slice(0, 40));
}
const chartBlock = renderMarkdown('```chart\n{"type":"bar","labels":["a"],"series":[{"name":"x","values":[1]}]}\n```');
assert.ok(chartBlock.includes('data-assistant-chart=') && chartBlock.includes('<svg'));
assert.ok(renderMarkdown('```chart\nnot json <b>\n```').includes('<pre><code>not json &lt;b&gt;</code></pre>'));

console.log('assistant-format: ok');
