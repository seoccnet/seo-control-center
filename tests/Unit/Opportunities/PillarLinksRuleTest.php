<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\PanelSnapshot;
use SeoControlCenter\Domain\Opportunities\PlaybookLibrary;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\PillarLinksRule;

/** Topic pages that do not link to their topic's main page (0.42.0). */
final class PillarLinksRuleTest extends TestCase
{
    private const string PILLAR = 'https://s.test/blog/mold-guide';

    public function testSupportingPagesWithoutATextLinkToTheMainPageAreFound(): void
    {
        $pages = [
            ['url' => self::PILLAR, 'clicks' => 300, 'impressions' => 9000],
            ['url' => 'https://s.test/blog/blossom-rot', 'clicks' => 40, 'impressions' => 1200],
            ['url' => 'https://s.test/blog/humidity', 'clicks' => 20, 'impressions' => 800],
            ['url' => 'https://s.test/blog/quiet', 'clicks' => 0, 'impressions' => 10],
            ['url' => 'https://s.test/blog/uncrawled', 'clicks' => 30, 'impressions' => 900],
        ];
        $audit = array_map(static fn(string $u): array => ['url' => $u, 'inbound' => 1, 'words' => 800, 'noindex' => false], [self::PILLAR, 'https://s.test/blog/blossom-rot', 'https://s.test/blog/humidity', 'https://s.test/blog/quiet']);
        $panel = new PanelSnapshot(auditPages: $audit, outgoing: ['https://s.test/blog/blossom-rot' => [self::PILLAR]], coverage: ['internal_links' => ['status' => 'available', 'date' => '2026-10-24']], bodyOutgoing: ['https://s.test/blog/humidity' => [self::PILLAR . '/']]);
        $signals = new ProjectSignals(coverage: ['internal_links' => ['status' => 'available', 'date' => '2026-10-24']], panel: $panel, topics: [['label' => 'mold on pepper', 'owner' => self::PILLAR, 'manual' => false, 'share' => 0.6, 'pages' => $pages]]);
        $found = (new PillarLinksRule())->evaluate($signals);

        self::assertCount(1, $found);
        $o = $found[0];
        self::assertSame([OpportunityType::InternalLinks, 'pillar:' . self::PILLAR, '1 page about “mold on pepper” does not link to its main page'], [$o->type, $o->subject, $o->title], 'A menu link (outgoing only) does not count; a text link with a trailing slash does; quiet and uncrawled pages are skipped.');
        self::assertContains(['label' => 'Pages without a link in the text', 'value' => '/blog/blossom-rot (1,200 impressions)'], $o->evidence);
        self::assertContains(['label' => 'Main page from', 'value' => 'Search Console: it gets 60.0 % of the topic’s searches'], $o->evidence);
        self::assertSame(1, $o->urlsTouched);

        $plan = (new PlaybookLibrary())->for($o->type, $o->subject, $o->evidence);
        self::assertSame(['internal_links:pillar', 'tested'], [$plan->key, $plan->tested]);
        self::assertStringContainsString('/blog/blossom-rot (1,200 impressions)', $plan->items('When it applies')[0]);
        self::assertStringContainsString(self::PILLAR, $plan->items('What to change')[0]);

        self::assertSame([], (new PillarLinksRule())->evaluate(new ProjectSignals(panel: $panel, topics: [['label' => 'x', 'owner' => 'https://s.test/not-crawled', 'manual' => true, 'share' => null, 'pages' => $pages]], coverage: ['internal_links' => ['status' => 'available', 'date' => '2026-10-24']])), 'An uncrawled main page: its links are unknown.');
        self::assertSame([], (new PillarLinksRule())->evaluate(new ProjectSignals(panel: $panel, topics: [['label' => 'x', 'owner' => self::PILLAR, 'manual' => true, 'share' => null, 'pages' => $pages]], coverage: ['internal_links' => ['status' => 'missing', 'date' => null]])), 'No usable link graph.');

        $product = 'https://s.test/seeds/blue-lobster';
        $withProduct = new PanelSnapshot(auditPages: [...$audit, ['url' => $product, 'inbound' => 3, 'words' => 400, 'noindex' => false]], coverage: ['internal_links' => ['status' => 'available', 'date' => '2026-10-24']]);
        $topic = static fn(string $owner, bool $manual, ?float $share): ProjectSignals => new ProjectSignals(coverage: ['internal_links' => ['status' => 'available', 'date' => '2026-10-24']], panel: $withProduct, topics: [['label' => 'slicer vs paste', 'owner' => $owner, 'manual' => $manual, 'share' => $share, 'pages' => $pages]]);
        self::assertSame([], (new PillarLinksRule())->evaluate($topic($product, false, 0.34)), 'An inferred product page leading with 34 % is not a hub (the 0.42.0 Web Shop case).');
        self::assertSame([], (new PillarLinksRule())->evaluate($topic($product, false, 0.8)), 'Nor with most of the impressions: only articles are inferred hubs.');
        self::assertSame([], (new PillarLinksRule())->evaluate($topic(self::PILLAR, false, 0.4)), 'An inferred article below half of the topic is not clear enough.');
        self::assertCount(1, (new PillarLinksRule())->evaluate($topic($product, true, 0.34)), 'A main page a person set is trusted, whatever its kind.');
    }
}
