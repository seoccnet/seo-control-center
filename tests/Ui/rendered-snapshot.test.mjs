import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../app/Modules/SiteAudit/RenderedPageProvider.php', import.meta.url), 'utf8');
const script = source.match(/<<<'JS'\r?\n([\s\S]*?)\r?\n\s*JS;/)[1].replace(/^ {8}/gm, '');
const image = (attrs = {}) => ({ currentSrc: 'https://example.test/placeholder.png', src: '', getAttribute: a => attrs[a], hasAttribute: () => true });
function snapshot(images, loaded = [], nodes = [], style = () => ({ backgroundImage: 'none' }), sources = [], base = 'https://example.test/') {
    return vm.runInNewContext(script, {
        document: { title: 'Test', baseURI: base, querySelector: () => null, querySelectorAll: s => s === 'img' ? images : s === '*' ? nodes : s === 'img,picture source' ? [...images, ...sources] : [] },
        location: { href: 'https://example.test/' },
        performance: { getEntriesByType: () => loaded },
        getComputedStyle: style,
    }, { timeout: 700 });
}

test('placeholder sources retain separate lazy declarations without fetching or interaction', () => {
    assert.ok(script.length <= 2000);
    const result = snapshot([image({ 'data-src': '/real.png', 'data-original': '/original.png', 'data-lazy-src': '/lazy.png' })]);
    assert.deepEqual(Array.from(result.lazy_images), ['/real.png', '/original.png', '/lazy.png']);
    assert.equal(result.resources[0].url, 'https://example.test/placeholder.png');
    assert.equal(result.resources[0].kind, 'image');
});

test('document base is captured independently from page identity and oversized bases are omitted', () => {
    const result = snapshot([], [], [], undefined, [], 'https://cdn.example.test/assets/');
    assert.equal(result.base_url, 'https://cdn.example.test/assets/');
    assert.equal(result.url, 'https://example.test/');
    assert.equal(snapshot([], [], [], undefined, [], 'https://example.test/' + 'x'.repeat(2048)).base_url, null);
});

test('responsive declarations include picture sources and data-srcset without truncating URLs', () => {
    const result = snapshot([image({ srcset: '/small.png 1x, /large.png 2x', 'data-srcset': '/lazy.png 480w' })], [], [], undefined,
        [image({ srcset: '/picture.webp 2x' }), image({ srcset: 'x'.repeat(2049) })]);
    assert.deepEqual(Array.from(result.srcsets), ['/small.png 1x, /large.png 2x', '/lazy.png 480w', '/picture.webp 2x']);
    assert.equal(snapshot(Array.from({ length: 30 }, () => image({ srcset: '/one 1x', 'data-srcset': '/two 2x' }))).srcsets.length, 20);
    assert.equal(snapshot(Array.from({ length: 20 }, () => image()), [], [], undefined, [image({ srcset: '/outside-sample.png' })]).srcsets.length, 0);
});

test('element and pseudo-element CSS backgrounds keep their evidence kind and quoted parentheses', () => {
    const result = snapshot([], [], [{}], (el, pseudo) => ({ backgroundImage: pseudo === '::before'
        ? 'url("/before(1).png"), linear-gradient(red, blue), url(\'/second.png\')'
        : pseudo === '::after' ? 'url(/after.png)' : 'url("/base.png")' }));
    assert.deepEqual(Array.from(result.resources, r => [r.url, r.kind]), [
        ['/base.png', 'css_background'], ['/before(1).png', 'css_before_background'],
        ['/second.png', 'css_before_background'], ['/after.png', 'css_after_background'],
    ]);
});

test('CSS sampling stops at 100 resources and only inspects the first 100 elements', () => {
    let calls = 0;
    const result = snapshot([], [], Array.from({ length: 120 }, () => ({})), () => {
        calls++;
        return { backgroundImage: 'url("/image.png")' };
    });
    assert.equal(calls, 100);
    assert.equal(result.resources.length, 100);
    calls = 0;
    snapshot([], [], Array.from({ length: 120 }, () => ({})), () => { calls++; return { backgroundImage: 'none' }; });
    assert.equal(calls, 300);
});

test('lazy sample survives a full resource sample and bounds images, URLs and output', () => {
    const images = Array.from({ length: 50 }, (_, i) => image({ 'data-src': i === 0 ? 'x'.repeat(3000) : `/lazy-${i}.png` }));
    const result = snapshot(images, Array.from({ length: 60 }, (_, i) => ({ name: `/loaded-${i}` })));
    assert.equal(result.resources.length, 100);
    assert.equal(result.lazy_images.length, 40);
    assert.equal(result.lazy_images[0].length, 2048);
    assert.equal(result.lazy_images[39], '/lazy-39.png');
    assert.equal(snapshot([image()]).lazy_images.length, 0);
});
