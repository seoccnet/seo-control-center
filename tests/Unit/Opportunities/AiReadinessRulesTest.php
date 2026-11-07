<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck;
use SeoControlCenter\Domain\AiVisibility\AiCrawlers;
use SeoControlCenter\Domain\AiVisibility\CitationReadiness;
use SeoControlCenter\Domain\Analytics\AiReferralSources;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\PlaybookLibrary;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\AiCrawlerRule;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\GoogleGa4Provider;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;
use SeoControlCenter\Modules\SiteAudit\Crawler\RobotsTxt;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Ga4Fixture;

/**
 * AI visibility readiness (0.43.0): robots.txt matching per AI crawler, the
 * blocked-crawler finding, citation signals and checklist, and AI referral
 * sources in GA4.
 */
final class AiReadinessRulesTest extends TestCase
{
    public function testExactRobotsMatchingKeepsSimilarTokensApart(): void
    {
        $text = "User-agent: *\nDisallow: /private\n\nUser-agent: Applebot\nDisallow: /\n";

        $applebot = RobotsTxt::parse($text, 'Applebot', true);
        self::assertTrue($applebot->named);
        self::assertFalse($applebot->allows('/'));
        $extended = RobotsTxt::parse($text, 'Applebot-Extended', true);
        self::assertFalse($extended->named, 'exact matching: the Applebot group is not the Applebot-Extended group');
        self::assertTrue($extended->allows('/'));
        self::assertFalse($extended->allows('/private/x'));
        self::assertFalse(RobotsTxt::parse($text, 'Applebot-Extended')->allows('/'), 'the crawler keeps its substring matching');
        self::assertSame(AiCrawlers::PARTIAL, AiCrawlers::state(true, ['/private/x']));
        self::assertSame(AiCrawlers::BLOCKED, AiCrawlers::state(false, []));
    }

    public function testBlockedSearchCrawlersBecomeOneFindingAndTrainingCrawlersNever(): void
    {
        $rule = new AiCrawlerRule();
        self::assertSame([], $rule->evaluate(new ProjectSignals()));
        self::assertSame([], $rule->evaluate(new ProjectSignals(aiCrawlers: $this->check(['GPTBot' => AiCrawlers::BLOCKED, 'ClaudeBot' => AiCrawlers::BLOCKED]))), 'training crawlers are the owner’s choice');

        $found = $rule->evaluate(new ProjectSignals(aiCrawlers: $this->check(['OAI-SearchBot' => AiCrawlers::BLOCKED, 'PerplexityBot' => AiCrawlers::PARTIAL, 'GPTBot' => AiCrawlers::BLOCKED])));
        self::assertCount(1, $found);
        self::assertSame(OpportunityType::AiVisibility, $found[0]->type);
        self::assertSame('crawler:ai_search', $found[0]->subject);
        self::assertSame('robots.txt blocks OAI-SearchBot, PerplexityBot', $found[0]->title);
        self::assertStringContainsString('does not let OAI-SearchBot fetch your home page, and keeps others out of some top pages', $found[0]->explanation);
        self::assertSame('OAI-SearchBot (whole site), PerplexityBot (some top pages)', $found[0]->evidence[0]['value']);
        self::assertSame('/blog/a', $found[0]->evidence[1]['value']);
        self::assertSame(60, $found[0]->impact);
        self::assertSame(90, $rule->evaluate(new ProjectSignals(aiCrawlers: $this->check(['Googlebot' => AiCrawlers::PARTIAL])))[0]->impact, 'Google and Bing search matter most');

        self::assertSame(['ai_crawlers'], SignalRequirements::for('ai_visibility', 'crawler:ai_search'));
        self::assertSame(['ai_mentions'], SignalRequirements::for('ai_visibility', 'llm:chat_gpt'));
        self::assertSame('ai_visibility:crawler', PlaybookLibrary::key(OpportunityType::AiVisibility, 'crawler:ai_search'));
        $playbook = (new PlaybookLibrary())->for(OpportunityType::AiVisibility, 'crawler:ai_search', $found[0]->evidence);
        self::assertSame('Let AI search crawlers fetch your pages', $playbook->title);
        self::assertSame('guidance', $playbook->tested);
        self::assertStringContainsString('OAI-SearchBot (whole site)', $playbook->goal);
    }

    public function testHtmlPageReadsCitationSignalsFromTheBodyText(): void
    {
        $html = '<html><head><meta property="article:modified_time" content="2026-10-01"></head><body>'
            . '<nav><ul><li>What is this menu about anyway?</li><li>Second menu entry here ok</li><li>Third menu entry here ok</li></ul><h2>How does the menu work?</h2></nav>'
            . '<main><p>Short.</p><p>Red running shoes last about 800 kilometres, and a good pair costs between 90 and 150 euros in most shops today.</p>'
            . '<h2>How long do running shoes last?</h2><h3>Which size should I pick</h3><h2>Our shoes</h2>'
            . '<p>Sales grew 25 % in 2025, to 1,200 pairs a month.</p>'
            . '<table><tr><th>Model</th><th>Price</th></tr><tr><td>Fast</td><td>99</td></tr></table>'
            . '<ul><li>Clean the soles after every run</li><li>Dry them away from the heater</li><li>Replace the insoles every season</li></ul>'
            . '<ul><li><a href="/a">A link that fills the item</a></li><li><a href="/b">Another link filling it</a></li><li><a href="/c">Third link filling it too</a></li></ul>'
            . '</main></body></html>';

        $page = HtmlPage::parse($html, 'https://shop.acme.test/guide');
        self::assertSame(['questions' => 2, 'tables' => 1, 'lists' => 1, 'figures' => 2, 'lead_words' => 21, 'updated' => true], $page->citation);
        $year = HtmlPage::parse('<html><body><p>Founded in 2019 by two runners who wanted better shoes for long trails.</p></body></html>', 'https://shop.acme.test/');
        self::assertSame(0, $year->citation['figures'] ?? null, 'a lone year is not a figure');

        $plain = HtmlPage::parse('<html><head><script type="application/ld+json">{"@type":"Article","dateModified":"2026-10-01"}</script></head><body><p>One two three</p></body></html>', 'https://shop.acme.test/');
        self::assertTrue($plain->citation['updated'] ?? false, 'dateModified in JSON-LD');
        self::assertArrayHasKey('lead_words', $plain->citation);
        self::assertNull($plain->citation['lead_words'], 'no paragraph of eight words or more');
    }

    public function testCitationChecklistCountsOnlyKnownChecks(): void
    {
        $checks = CitationReadiness::checks(true, ['BreadcrumbList'], 3, null);
        self::assertSame([true, false, true, null, null, null, null, null], array_column($checks, 'passed'), 'pages crawled before 0.43.0 are unknown, not failing');
        self::assertSame(['passed' => 2, 'known' => 3, 'unknown' => 5], CitationReadiness::score($checks));
        self::assertSame(['tested', 'tested', 'tested', 'tested', 'tested', 'varies', 'varies', 'early'], array_column($checks, 'tested'));

        $full = CitationReadiness::checks(false, ['Product'], 0, ['questions' => 1, 'tables' => 0, 'lists' => 1, 'figures' => 0, 'lead_words' => 81, 'updated' => false]);
        self::assertSame([false, true, false, false, true, true, false, false], array_column($full, 'passed'));
        $product = CitationReadiness::checks(false, ['Product'], 0, ['questions' => 0, 'tables' => 1, 'lists' => 0, 'figures' => 2, 'lead_words' => 30, 'updated' => false], false);
        self::assertSame([null, true, null, true, true, false, false, true], array_column($product, 'passed'), 'finding 36: author and sources apply to articles only');
        self::assertSame(['passed' => 4, 'known' => 6, 'unknown' => 0], CitationReadiness::score($product, false));
        $legacy = CitationReadiness::checks(null, ['Product'], null, null, false);
        self::assertSame(['passed' => 1, 'known' => 1, 'unknown' => 5], CitationReadiness::score($legacy, false), 'finding 38: a product page crawled before 0.43.0 has five applicable checks still unknown');
        self::assertSame(7, CitationReadiness::score($legacy)['unknown'], 'an article would also wait for author and sources');
        self::assertTrue(CitationReadiness::checks(null, null, null, ['questions' => 0, 'tables' => 0, 'lists' => 0, 'figures' => 0, 'lead_words' => 20, 'updated' => false])[7]['passed']);
    }

    public function testAiReferralSourcesAndTheGa4Request(): void
    {
        self::assertSame('ChatGPT', AiReferralSources::assistant('chatgpt.com'));
        self::assertSame('ChatGPT', AiReferralSources::assistant('www.ChatGPT.com'));
        self::assertSame('Perplexity', AiReferralSources::assistant('perplexity'));
        self::assertSame('Copilot', AiReferralSources::assistant('edgeservices.bing.com'));
        self::assertNull(AiReferralSources::assistant('google'));
        self::assertNull(AiReferralSources::assistant('notchatgpt.com'));
        self::assertSame(1, preg_match('/^' . AiReferralSources::pattern() . '$/', 'gemini.google.com'));
        self::assertSame(0, preg_match('/^' . AiReferralSources::pattern() . '$/', 'google.com'));

        $http = new FakeHttpClient();
        $http->json('POST', GoogleGa4Provider::TOKEN_ENDPOINT, ['access_token' => 'test-token']);
        $http->json('POST', GoogleGa4Provider::API . '123:runReport', [
            'dimensionHeaders' => [['name' => 'date'], ['name' => 'sessionSource']],
            'metricHeaders' => [['name' => 'sessions'], ['name' => 'keyEvents'], ['name' => 'purchaseRevenue']],
            'rows' => [
                ['dimensionValues' => [['value' => '20261001'], ['value' => 'ChatGPT.com']], 'metricValues' => [['value' => '4'], ['value' => '1'], ['value' => '49.5']]],
                ['dimensionValues' => [['value' => '20261001'], ['value' => 'google']], 'metricValues' => [['value' => '9'], ['value' => '0'], ['value' => '0']]],
                ['dimensionValues' => [['value' => '20260901'], ['value' => 'perplexity.ai']], 'metricValues' => [['value' => '2'], ['value' => '0'], ['value' => '0']]],
            ],
            'metadata' => ['currencyCode' => 'EUR'], 'rowCount' => 3,
        ]);
        $provider = new GoogleGa4Provider($http, new FrozenClock(new \DateTimeImmutable('2026-10-25 UTC')));
        $report = $provider->aiReferrals(Ga4Fixture::credentials(), '123', 'www.acme.test', new DateRange('2026-09-27', '2026-10-24'));

        self::assertSame([['date' => '2026-10-01', 'source' => 'chatgpt.com', 'sessions' => 4, 'key_events' => 1.0, 'revenue' => 49.5]], $report->rows, 'other sources and days outside the range are dropped');
        self::assertSame('EUR', $report->currency);
        $body = json_decode((string) $http->requests[1]['body'], true);
        self::assertIsArray($body);
        self::assertSame(['www.acme.test', 'acme.test'], $body['dimensionFilter']['andGroup']['expressions'][0]['filter']['inListFilter']['values']);
        self::assertSame('FULL_REGEXP', $body['dimensionFilter']['andGroup']['expressions'][1]['filter']['stringFilter']['matchType']);
    }

    /** @param array<string, string> $states */
    private function check(array $states): AiCrawlerCheck
    {
        $agents = [];
        foreach (array_keys(AiCrawlers::AGENTS) as $token) {
            $state = $states[$token] ?? AiCrawlers::ALLOWED;
            $agents[] = ['token' => $token, 'state' => $state, 'named' => isset($states[$token]), 'blocked' => $state === AiCrawlers::PARTIAL ? ['/blog/a'] : []];
        }

        return new AiCrawlerCheck(1, 'https://shop.acme.test/robots.txt', 'ok', 200, $agents, 'missing', 404, null, '2026-10-25 08:00:00');
    }
}
