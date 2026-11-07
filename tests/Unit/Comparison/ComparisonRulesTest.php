<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Comparison;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\Coverage;
use SeoControlCenter\Domain\Comparison\Diagnosis;
use SeoControlCenter\Domain\Comparison\OutlineExtractor;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Comparison\PageTypeClassifier;
use SeoControlCenter\Domain\Comparison\ResultsFormat;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\CompetitorAnalysisRule;

final class ComparisonRulesTest extends TestCase
{
    /** @param list<string> $headings */
    public static function article(string $title, array $headings, int $words = 400, string $extra = ''): string
    {
        $body = '';
        foreach ($headings as $h) {
            $body .= '<h2>' . $h . '</h2><p>' . str_repeat('lorem ipsum dolor ', intdiv($words, max(1, count($headings)) * 3)) . '</p>';
        }

        return '<!doctype html><html><head><title>' . $title . '</title><meta name="description" content="About ' . $title . '"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","datePublished":"2026-09-01","dateModified":"2026-09-20"}</script></head><body><h1>' . $title . '</h1><p class="byline">By Ann</p>' . $body . $extra . '<script>ignore("me")</script></body></html>';
    }

    public static function product(string $title): string
    {
        return '<!doctype html><html><head><title>' . $title . '</title><script type="application/ld+json">{"@type":"Product","offers":{"@type":"Offer","price":"9.99"}}</script></head><body><h1>' . $title . '</h1><span class="price">9.99</span><button>Add to cart</button><select name="size"><option>1</option></select><p>' . str_repeat('great seeds for growers ', 30) . '</p><h2>Specifications</h2><h2>Reviews</h2><img src="a.jpg"><img src="b.jpg"></body></html>';
    }

    public function testExtractorKeepsStructureNotTextAndDetectsChallenges(): void
    {
        $page = OutlineExtractor::extract(self::article('How to grow sunflowers', ['When to plant', 'How long does flowering take?', 'Best soil'], 600, '<a href="/seeds">x</a><a href="https://other.test/a">y</a><table></table><img src="x.jpg">'), 'https://s.test/blog/grow');
        self::assertTrue($page->readable());
        self::assertSame('How to grow sunflowers', $page->title);
        self::assertSame(['When to plant', 'How long does flowering take?', 'Best soil'], array_column($page->outline, 'text'));
        self::assertSame(['When to plant', 'How long does flowering take?'], $page->questions, 'Headings that ask or start with a question word.');
        self::assertSame(['Article'], $page->schemaTypes);
        self::assertSame(['2026-09-01', '2026-09-20'], [$page->published, $page->modified]);
        self::assertSame([1, 1, 1, 1], [$page->internalLinks, $page->outboundLinks, $page->tables, $page->images]);
        self::assertTrue($page->elements['byline']);
        self::assertGreaterThan(300, $page->wordCount);
        self::assertStringNotContainsString('lorem', json_encode($page->toArray(), JSON_THROW_ON_ERROR), 'No body text is kept.');
        self::assertSame($page->toArray(), PageStructure::fromArray($page->toArray())->toArray());

        $challenge = OutlineExtractor::extract('<html><head><title>Just a moment...</title></head><body>Checking your browser before accessing the site. Enable JavaScript and cookies to continue.</body></html>', 'https://s.test/x');
        self::assertSame(PageStructure::BLOCKED, $challenge->state);
        self::assertSame(PageStructure::BLOCKED, OutlineExtractor::extract('', 'https://s.test/x')->state);
    }

    public function testPageTypesFromContentThenAddress(): void
    {
        $product = OutlineExtractor::extract(self::product('Blue Dream Seeds'), 'https://shop.test/blue-dream');
        self::assertSame('product', PageTypeClassifier::classify('https://shop.test/blue-dream', null, $product)['type']);
        self::assertContains('an add-to-cart button', PageTypeClassifier::classify('https://shop.test/blue-dream', null, $product)['signals']);
        $article = OutlineExtractor::extract(self::article('How to grow', ['A', 'B']), 'https://shop.test/products/how');
        $mixed = PageTypeClassifier::classify('https://shop.test/products/how', null, $article);
        self::assertSame(['article', 'medium'], [$mixed['type'], $mixed['confidence']], 'Content wins over a misleading address, with lower confidence.');
        self::assertSame('review', PageTypeClassifier::classify('https://s.test/x', null, OutlineExtractor::extract(self::article('10 Best Sunflower Seeds', ['A', 'B']), 'https://s.test/x'))['type']);
        self::assertSame(['forum', 'high'], [PageTypeClassifier::classify('https://www.reddit.com/r/x/comments/1', 'thread')['type'], PageTypeClassifier::classify('https://www.reddit.com/r/x/comments/1', 'thread')['confidence']]);
        self::assertSame('homepage', PageTypeClassifier::classify('https://s.test/', 'Seeds shop')['type']);
        self::assertSame('category', PageTypeClassifier::classify('https://s.test/collections/sunflower', 'Sunflower seeds')['type']);
    }

    public function testResultsFormatVerdicts(): void
    {
        $rows = static fn(array $types): array => array_map(static fn(int $i, string $t): array => ['position' => $i + 1, 'type' => $t], array_keys($types), $types);
        self::assertSame(['dominant', ['product']], array_slice(array_values(ResultsFormat::decide($rows(['product', 'product', 'product', 'category', 'product', 'product', 'article']))), 0, 2));
        self::assertSame('mixed', ResultsFormat::decide($rows(['product', 'article', 'product', 'article', 'category', 'article', 'product']))['verdict']);
        self::assertSame('fragmented', ResultsFormat::decide($rows(['product', 'article', 'forum', 'video', 'category', 'tool', 'other']))['verdict']);
        self::assertSame('fragmented', ResultsFormat::decide($rows(['other', 'other', 'other']))['verdict'], 'Other never dominates.');
    }

    public function testCoverageAndDiagnosis(): void
    {
        $c1 = OutlineExtractor::extract(self::article('Grow sunflowers', ['Best soil for sunflowers', 'Watering schedule', 'Lighting hours', 'Harvest time'], 1500), 'https://a.test/grow');
        $c2 = OutlineExtractor::extract(self::article('Sunflower guide', ['Soil for sunflowers', 'Watering schedule tips', 'Lighting hours per day', 'Pests'], 1500), 'https://b.test/guide');
        $own = OutlineExtractor::extract(self::article('Our sunflower guide', ['Harvest time'], 300), 'https://me.test/guide');
        $coverage = Coverage::compare($own, [$c1, $c2]);
        self::assertSame(['Best soil for sunflowers', 'Lighting hours', 'Watering schedule'], array_column($coverage, 'topic'), 'Covered by both competitors; single-page headings do not count; ties by name.');
        self::assertFalse($coverage[0]['own']);

        $format = ResultsFormat::decide([['position' => 1, 'type' => 'article'], ['position' => 2, 'type' => 'article'], ['position' => 3, 'type' => 'article']]);
        $type = PageTypeClassifier::classify('https://me.test/guide', null, $own);
        $competitors = [['url' => 'https://a.test/grow', 'position' => 1, 'type' => 'article', 'page' => $c1], ['url' => 'https://b.test/guide', 'position' => 2, 'type' => 'article', 'page' => $c2]];
        $result = Diagnosis::diagnose($format, 'https://me.test/guide', $own, $type, $competitors, $coverage, ['sunflower guide'], '2026-10-16');
        self::assertSame('content', $result['bottleneck']);
        self::assertSame(['add_section', 'expand_section'], array_slice(array_column($result['actions'], 'type'), 0, 2));
        foreach ($result['actions'] as $action) {
            self::assertSame([], array_diff($action['evidence'], ['https://a.test/grow', 'https://b.test/guide']), 'Evidence only cites fetched competitors.');
        }

        $shop = ResultsFormat::decide([['position' => 1, 'type' => 'product'], ['position' => 2, 'type' => 'product'], ['position' => 3, 'type' => 'category']]);
        $mismatch = Diagnosis::diagnose($shop, 'https://me.test/guide', $own, $type, [], [], ['buy sunflower seeds'], '2026-10-16');
        self::assertSame('format', $mismatch['bottleneck']);
        self::assertSame('create_page', $mismatch['actions'][0]['type']);
        self::assertStringContainsString('Do not rewrite your article', $mismatch['actions'][0]['what']);

        $blocked = Diagnosis::diagnose($format, 'https://me.test/guide', PageStructure::unread('https://me.test/guide', PageStructure::BLOCKED, 'robots.txt'), null, $competitors, [], ['x'], '2026-10-16');
        self::assertSame('technical', $blocked['bottleneck']);
        $none = Diagnosis::diagnose($format, null, null, null, $competitors, [], ['sunflower guide'], '2026-10-16');
        self::assertSame(['format', 'create_page'], [$none['bottleneck'], $none['actions'][0]['type']]);
        $even = Diagnosis::diagnose($format, 'https://a.test/grow', $c1, PageTypeClassifier::classify('https://a.test/grow', null, $c1), [['url' => 'https://b.test/guide', 'position' => 2, 'type' => 'article', 'page' => $c2]], [], ['grow sunflowers'], '2026-10-16');
        self::assertSame('unclear', $even['bottleneck']);
        self::assertStringContainsString('links or domain authority', $even['reasons'][0]);
    }

    public function testActionsBecomeOpportunitiesWithStableSubjects(): void
    {
        $analysis = new Analysis('0192f0a0-0000-7000-8000-0000000000d1', 1, 1, '0192f0a0-0000-7000-8000-0000000000c1', 'sunflower guide', 'sunflower guide', ['sunflower guide'], 2000.0, Analysis::DONE, Analysis::STORED, 2840, 'en', [], 'https://me.test/guide', 8, [], [
            'format' => ['verdict' => 'dominant', 'types' => ['article'], 'shares' => [], 'explanation' => 'Google ranks articles here.'],
            'diagnosis' => ['bottleneck' => 'content', 'reasons' => ['3 subtopics missing.'], 'confidence' => 'high', 'actions' => [
                ['type' => 'add_section', 'title' => 'Cover the missing subtopics', 'what' => 'Add sections.', 'evidence' => ['https://a.test/grow'], 'effort' => 'medium', 'weight' => 0.8],
                ['type' => 'page_pair', 'title' => 'Pair your page', 'what' => 'Pair it.', 'evidence' => [], 'effort' => 'high', 'weight' => 0.7],
            ]],
        ], null, null, '2026-10-16 08:00:00', '2026-10-16 08:05:00');
        $found = (new CompetitorAnalysisRule())->evaluate(new ProjectSignals(comparisons: [$analysis]));
        self::assertSame([OpportunityType::CompetitorGap, OpportunityType::FormatMismatch], array_map(static fn($o) => $o->type, $found));
        self::assertSame('analysis:0192f0a0-0000-7000-8000-0000000000c1:add_section', $found[0]->subject);
        $evidence = array_column($found[0]->evidence, 'value', 'label');
        self::assertSame('https://me.test/guide', $evidence['Page']);
        self::assertSame('content', $evidence['Main bottleneck']);
        self::assertStringContainsString('clicks a month if the page reaches position 3', $evidence['Estimated gain']);
        self::assertGreaterThan(0.0, $found[0]->estimatedClicks);
        self::assertSame(['competitor_analysis'], (new CompetitorAnalysisRule())->requires($found[0]));
    }
}
