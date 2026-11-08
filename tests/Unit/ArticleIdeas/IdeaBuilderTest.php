<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\ArticleIdeas;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\IdeaBuilder;

final class IdeaBuilderTest extends TestCase
{
    public function testOnlyRelevantCompetitorArticlesWithDemandBecomeIdeas(): void
    {
        $ideas = $this->build([
            self::row('how to germinate seeds', 'https://rival.test/blog/germinate-seeds', 900),
            self::row('buy seeds online', 'https://rival.test/products/seed-pack', 5000),
            self::row('seed shop', 'https://rival.test/', 3000),
            self::row('rare seed trivia', 'https://rival.test/blog/trivia', 5),
            self::row('free seeds giveaway', 'https://rival.test/blog/giveaway', 800),
        ], relevant: static fn(string $k): bool => $k !== 'free seeds giveaway');

        self::assertSame(['how to germinate seeds'], array_map(static fn(ArticleIdea $i): string => $i->mainKeyword, $ideas), 'Product, home, tiny and ruled-out searches are left out.');
        self::assertSame(ArticleIdea::NEW, $ideas[0]->kind);
        self::assertSame('https://rival.test/blog/germinate-seeds', $ideas[0]->competitors[0]['url']);
        self::assertSame('No page of yours ranks for these searches.', $ideas[0]->reasons[1]);
    }

    public function testYourRankingDecidesBetweenNewUpdateAndNothing(): void
    {
        $rows = [
            self::row('how to germinate seeds', 'https://rival.test/blog/germinate-seeds', 900),
            self::row('best grow lights', 'https://other.test/best-grow-lights', 700, domain: 'other.test'),
            self::row('what is hydroponics', 'https://rival.test/guides/hydroponics', 400),
        ];
        $ideas = $this->build($rows, own: [
            'how to germinate seeds' => ['url' => 'https://shop.acme.test/blog/germination', 'position' => 12.4, 'impressions' => 300],
            'best grow lights' => ['url' => 'https://shop.acme.test/lights', 'position' => 2.0, 'impressions' => 900],
            'what is hydroponics' => ['url' => 'https://shop.acme.test/about', 'position' => 45.0, 'impressions' => 10],
        ]);
        $byKeyword = array_column(array_map(static fn(ArticleIdea $i): array => ['k' => $i->mainKeyword, 'kind' => $i->kind], $ideas), 'kind', 'k');

        self::assertSame(['how to germinate seeds' => ArticleIdea::UPDATE, 'what is hydroponics' => ArticleIdea::NEW], $byKeyword, 'Top 3 already: no idea; within 30: update; beyond: new.');
        self::assertStringContainsString('/blog/germination already ranks around position 12.4', $ideas[0]->reasons[1]);
        self::assertSame(['low' => 1, 'high' => 2], array_intersect_key($ideas[0]->prediction['months'], ['low' => 0, 'high' => 0]), 'An update of a low-difficulty topic.');
    }

    public function testPredictionsShowRangesAndTheirBasis(): void
    {
        $ideas = $this->build([self::row('how to build a greenhouse', 'https://rival.test/blog/greenhouse', 1000, difficulty: 70, avgRd: 60)], ownRd: 8);
        $p = $ideas[0]->prediction;

        self::assertSame(['low' => 20, 'expected' => 50, 'high' => 150], $p['traffic'], 'Volume × click-through at positions 9, 5 and 2.');
        self::assertSame('hard', $p['difficulty']['level']);
        self::assertCount(3, $p['difficulty']['reasons'], 'Keyword difficulty, page-one links and your own domain.');
        self::assertNotNull($p['backlinks']);
        self::assertSame([18, 60], [$p['backlinks']['low'], $p['backlinks']['high']]);
        self::assertSame([6, 12], [$p['months']['low'], $p['months']['high']]);
        self::assertStringContainsString('An assumption', $p['months']['basis']);

        $easy = $this->build([self::row('how to water seedlings', 'https://rival.test/blog/water', 200, difficulty: 10, avgRd: null)])[0]->prediction;
        self::assertSame('low', $easy['difficulty']['level']);
        self::assertNull($easy['backlinks'], 'Unknown without referring-domain data.');
    }

    public function testTheSitesOwnTimeToRankReplacesTheAssumption(): void
    {
        $ctr = static fn(int $p): float => 0.05;
        $keywords = [['keyword' => 'mold on pepper', 'volume' => 1000, 'difficulty' => 10]];
        $assumed = IdeaBuilder::predict($keywords, ArticleIdea::NEW, 4, null, $ctr, ['update:low' => ['low' => 1, 'high' => 1, 'count' => 5]]);
        self::assertSame([1, 3], [$assumed['months']['low'], $assumed['months']['high']], 'Another kind\'s calibration does not apply.');
        self::assertStringStartsWith('An assumption', $assumed['months']['basis']);

        $own = IdeaBuilder::predict($keywords, ArticleIdea::NEW, 4, null, $ctr, ['new:low' => ['low' => 2, 'high' => 4, 'count' => 3]]);
        self::assertSame([2, 4], [$own['months']['low'], $own['months']['high']]);
        self::assertSame('Your site: 3 tracked new articles of low difficulty reached the top 10 in about 2–4 months (middle half). Only articles that got there count.', $own['months']['basis']);
    }

    public function testIdeasAreRankedByScoreAndKeyedStably(): void
    {
        $rows = [
            self::row('how to prune tomatoes', 'https://rival.test/blog/prune', 300),
            self::row('how to germinate seeds', 'https://rival.test/blog/germinate', 2000, difficulty: 80, avgRd: 80),
            self::row('how to germinate seeds', 'https://other.test/guides/germinate', 2000, domain: 'other.test', difficulty: 80, avgRd: 80),
        ];
        $ideas = $this->build($rows);

        self::assertSame(['how to germinate seeds', 'how to prune tomatoes'], array_map(static fn(ArticleIdea $i): string => $i->mainKeyword, $ideas));
        self::assertCount(2, $ideas[0]->competitors);
        self::assertStringContainsString('2 competitor articles from 2 sites rank', $ideas[0]->reasons[0]);
        self::assertSame(hash('sha256', 'how to germinate seeds'), $ideas[0]->key);
        self::assertEquals($ideas[0], ArticleIdea::fromArray($ideas[0]->present()), 'Stored and read back unchanged.');
    }

    public function testOneCompetitorArticleIsOneIdea(): void
    {
        // 0.27.0 live check, findings 1 and 2: spelling variants answered by one article
        // were separate ideas, and unrelated posts of one site were chained together.
        $ideas = $this->build([
            self::row('what is npk', 'https://rival.test/blog/what-is-npk', 40500),
            self::row('what is n p k', 'https://rival.test/blog/what-is-npk', 40500),
            self::row('mortgage lifter cultivar', 'https://rival.test/blog/mortgage-lifter', 14800),
            self::row('lifter mortgage heirloom', 'https://rival.test/blog/mortgage-lifter', 9900),
            self::row('mortgage lifter cultivar', 'https://other.test/blog/gdp-cultivar', 14800, domain: 'other.test', position: 5),
            self::row('lifter mortgage heirloom', 'https://other.test/blog/gdp-cultivar', 9900, domain: 'other.test', position: 2),
            self::row('pepper leaf', 'https://rival.test/blog/how-many-leaves', 27100),
            self::row('bonsai pepper', 'https://rival.test/blog/bonsai-and-dwarfs', 27100),
        ]);
        $byKeyword = [];
        foreach ($ideas as $idea) {
            $byKeyword[$idea->mainKeyword] = $idea;
        }
        ksort($byKeyword);

        self::assertSame(['bonsai pepper', 'mortgage lifter cultivar', 'pepper leaf', 'what is n p k'], array_keys($byKeyword), 'One idea per article; separate posts stay separate.');
        self::assertSame(40500, $byKeyword['what is n p k']->volume, 'The same letters at the same volume count once.');
        self::assertCount(2, $byKeyword['what is n p k']->keywords);
        $gdp = $byKeyword['mortgage lifter cultivar'];
        self::assertSame(['https://other.test/blog/gdp-cultivar', 'https://rival.test/blog/mortgage-lifter'], array_column($gdp->competitors, 'url'), 'Both sites\' articles for the same searches.');
        self::assertSame([2, 3], array_column($gdp->competitors, 'position'));
        self::assertSame(24700, $gdp->volume);
    }

    public function testLanguageCopiesOfOneArticleAreOneIdea(): void
    {
        // 0.27.2 live check, finding 7: /blog-x and /us/blog-x were two ideas.
        $ideas = $this->build([
            self::row('npk brownies', 'https://rival.test/blog-the-ultimate-guide-to-pepper-brownies-n696', 9900, position: 11),
            self::row('pepper brownies', 'https://www.rival.test/us/blog-the-ultimate-guide-to-pepper-brownies-n696', 9900, position: 14),
            self::row('tomato emoji', 'https://rival.test/blog-best-pepper-emojis-n1415', 8100, position: 11),
            self::row('pepper emoji', 'https://rival.test/en-gb/blog-best-pepper-emojis-n1415/', 8100, position: 6),
            self::row('pepper butter', 'https://rival.test/blog-pepper-butter', 5000),
        ]);

        self::assertSame(['npk brownies', 'pepper emoji', 'pepper butter'], array_map(static fn(ArticleIdea $i): string => $i->mainKeyword, $ideas));
        self::assertCount(2, $ideas[0]->competitors, 'Both copies are listed.');
        self::assertSame(['npk brownies', 'pepper brownies'], array_column($ideas[0]->keywords, 'keyword'));
        self::assertSame('rival.test/blog-best-pepper-emojis-n1415', IdeaBuilder::articleKey('https://www.rival.test/en-gb/blog-best-pepper-emojis-n1415/'));
        self::assertSame('rival.test/usa/guide', IdeaBuilder::articleKey('https://rival.test/usa/guide'), 'Only a language or country folder is dropped.');
    }

    public function testSharedWordsAloneNeverMergeArticles(): void
    {
        // 0.28.1 and 0.28.3 live checks, findings 12 and 15: posts on flushing, topping and clones
        // joined one idea because their main searches share "pepper plant". With only a handful of
        // main searches the words are not even frequent, so no word threshold can tell them apart.
        $ideas = $this->build([
            self::row('cherry pepper plant', 'https://rival.test/blog/cherry', 5400),
            self::row('topping your pepper plant', 'https://rival.test/blog/topping', 2900),
            self::row('clones pepper plants', 'https://rival.test/blog/clones', 1900),
            self::row('overwatered pepper plants', 'https://rival.test/blog/flushing', 1600),
            self::row('mortgage lifter cultivar', 'https://rival.test/blog/gdp', 14800),
            self::row('mortgage lifter', 'https://other.test/blog/gdp-review', 9900, domain: 'other.test'),
        ]);
        $mains = array_map(static fn(ArticleIdea $i): string => $i->mainKeyword, $ideas);
        sort($mains);

        self::assertSame(['cherry pepper plant', 'clones pepper plants', 'mortgage lifter', 'mortgage lifter cultivar', 'overwatered pepper plants', 'topping your pepper plant'], $mains, 'Every article is its own idea: none ranks for another\'s main search.');
        self::assertSame(['pepper', 'plant'], IdeaBuilder::words('Pepper Plants'));
    }

    public function testArticlesMergeOnlyWhenEachRanksTopTenForTheOthersMainSearch(): void
    {
        // 0.28.2 live check, finding 14: broad posts of a strong site reach page two for the
        // cherry article's main search and were joined to it.
        $ideas = $this->build([
            self::row('cherry', 'https://rival.test/blog/cherry', 5400, position: 3),
            self::row('cherry seed', 'https://rival.test/blog/cherry', 210, position: 4),
            self::row('how to flush tomato', 'https://rival.test/blog/flushing', 110, position: 1),
            self::row('cherry', 'https://rival.test/blog/flushing', 5400, position: 15),
            self::row('topping your pepper plant', 'https://rival.test/blog/topping', 1300, position: 6),
            self::row('cherry', 'https://rival.test/blog/topping', 5400, position: 9),
            self::row('mortgage lifter cultivar', 'https://rival.test/blog/gdp', 14800, position: 2),
            self::row('lifter mortgage heirloom', 'https://rival.test/blog/gdp', 9900, position: 7),
            self::row('lifter mortgage heirloom', 'https://other.test/blog/gdp-review', 9900, domain: 'other.test', position: 3),
            self::row('mortgage lifter cultivar', 'https://other.test/blog/gdp-review', 14800, domain: 'other.test', position: 5),
        ]);
        $byMain = [];
        foreach ($ideas as $idea) {
            $byMain[$idea->mainKeyword] = array_column($idea->competitors, 'url');
        }
        ksort($byMain);

        self::assertSame(['cherry', 'how to flush tomato', 'mortgage lifter cultivar', 'topping your pepper plant'], array_keys($byMain), 'Flushing (15) and topping (9) rank for "cherry", but the cherry article does not rank for theirs.');
        self::assertSame(['https://rival.test/blog/cherry'], $byMain['cherry']);
        self::assertSame(['https://other.test/blog/gdp-review', 'https://rival.test/blog/gdp'], $byMain['mortgage lifter cultivar'], 'Each ranks top 10 for the other\'s main search.');
    }

    public function testCloseVariantsShareOneVolume(): void
    {
        // Finding 3: six variants with Google's shared 165,000 were summed.
        $url = 'https://rival.test/blog/slicer-vs-paste';
        $idea = $this->build([
            self::row('slicer vs paste', $url, 165000),
            self::row('paste vs slicer plants', $url, 165000),
            self::row('slicer blossom vs paste blossom', $url, 165000),
            self::row('difference between paste and slicer plants', $url, 33100),
            self::row('difference between slicer and paste plants', $url, 33100),
            self::row('paste plant', $url, 27100),
        ])[0];

        self::assertSame(165000 + 33100 + 27100, $idea->volume);
        self::assertCount(6, $idea->keywords, 'Every search is still listed.');
        self::assertContains('3 close variants share one search volume and are counted once.', $idea->reasons);
        self::assertSame(['slicer vs paste', 'difference between paste and slicer plants', 'paste plant'], array_column(IdeaBuilder::distinct($idea->keywords), 'keyword'));
    }

    public function testRankingsBeyondPageTwoAndShopPagesDoNotDecide(): void
    {
        // Findings 4 and 5: a position-52 ranking made an idea; a product page was offered as "your article".
        $ideas = $this->build([
            self::row('which bacterial cultivar is the least competitively dominant', 'https://rival.test/blog/low-odour-cultivars', 90500, position: 52),
            self::row('blue dream blossoms', 'https://rival.test/blog/blue-dream-guide', 3600),
        ], own: ['blue dream blossoms' => ['url' => 'https://shop.acme.test/seeds/blue-dream', 'position' => 27.5, 'impressions' => 40]]);

        self::assertSame(['blue dream blossoms'], array_map(static fn(ArticleIdea $i): string => $i->mainKeyword, $ideas));
        self::assertSame(ArticleIdea::NEW, $ideas[0]->kind);
        self::assertSame('Your page /seeds/blue-dream ranks around position 27.5, but it is a shop page, not an article: an article can answer the question and link to it.', $ideas[0]->reasons[1]);
        self::assertSame('https://shop.acme.test/seeds/blue-dream', $ideas[0]->own['url'] ?? null);
    }

    public function testEachIdeaCarriesItsMarket(): void
    {
        $ideas = $this->build([self::row('how to grow tomatoes', 'https://rival.test/blog/grow-tomatoes', 900)], market: '2840:en');

        self::assertSame(['2840:en'], $ideas[0]->marketKeys());
        self::assertSame('United States · English', $ideas[0]->marketLabel());
        self::assertSame(['market' => '2840:en', 'volume' => 900, 'searches' => 1, 'traffic' => $ideas[0]->prediction['traffic'], 'difficulty' => 'low', 'competitors' => 1], $ideas[0]->markets[0]);
        self::assertSame('2840:en', $ideas[0]->competitors[0]['market'] ?? null);
        self::assertSame(hash('sha256', 'how to grow tomatoes'), $ideas[0]->key, 'The key of a one-market idea is unchanged.');
        self::assertEquals($ideas[0], ArticleIdea::fromArray($ideas[0]->present()), 'Markets are stored and read back.');
        self::assertSame('United States · English', $ideas[0]->present()['market']);
    }

    public function testSameQueryInSameLanguageIsOneIdeaWithTheSplitPerMarket(): void
    {
        $us = $this->build([self::row('how to grow tomatoes', 'https://rival.test/blog/grow-tomatoes', 900), self::row('tomato seedlings care', 'https://rival.test/blog/seedlings', 300)], market: '2840:en');
        $ca = $this->build([self::row('how to grow tomatoes', 'https://rival.ca.test/blog/grow-tomatoes', 200, domain: 'rival.ca.test', difficulty: 70, avgRd: 60)], market: '2124:en');

        $ideas = IdeaBuilder::merge(['2840:en' => $us, '2124:en' => $ca]);
        $byLabel = array_column(array_map(static fn(ArticleIdea $i): array => [$i->mainKeyword, $i], $ideas), 1, 0);
        self::assertCount(2, $ideas, 'The same topic in two markets is listed once, not twice.');

        $joined = $byLabel['how to grow tomatoes'];
        self::assertSame(hash('sha256', 'how to grow tomatoes'), $joined->key);
        self::assertSame(['2840:en', '2124:en'], $joined->marketKeys(), 'Most volume first.');
        self::assertSame(1100, $joined->volume);
        self::assertSame([900, 200], array_column($joined->markets, 'volume'), 'The total is shown with its split.');
        self::assertSame(['2840:en' => 900, '2124:en' => 200], $joined->keywords[0]['volumes'] ?? null);
        self::assertSame($us[0]->prediction['traffic']['expected'] + $ca[0]->prediction['traffic']['expected'], $joined->prediction['traffic']['expected']);
        self::assertSame($ca[0]->prediction['difficulty'], $joined->prediction['difficulty'], 'Difficulty is that of the hardest market.');
        self::assertSame(['2840:en', '2124:en'], array_map(static fn(array $c): ?string => $c['market'] ?? null, $joined->competitors));
        self::assertStringStartsWith('Searched in 2 markets: United States · English 900, Canada · English 200 a month.', $joined->reasons[0]);

        $single = $byLabel['tomato seedlings care'];
        self::assertSame(['2840:en'], $single->marketKeys());
        self::assertSame($us[1], $single, 'An idea of one market is unchanged.');
    }

    public function testDifferentLanguagesNeverMergeOrAddUp(): void
    {
        $us = $this->build([self::row('sunflower seeds', 'https://rival.test/blog/sunflower-seeds', 900)], market: '2840:en');
        $de = $this->build([
            self::row('sunflower seeds', 'https://rival.de.test/blog/sunflower-seeds', 400, domain: 'rival.de.test'),
            self::row('sonnenblumen pflanzen', 'https://rival.de.test/blog/sonnenblumen', 700, domain: 'rival.de.test'),
        ], market: '2276:de');

        $ideas = IdeaBuilder::merge(['2840:en' => $us, '2276:de' => $de]);

        self::assertCount(3, $ideas, 'The same words in another language are another idea.');
        foreach ($ideas as $idea) {
            self::assertCount(1, $idea->markets, 'No idea adds up markets of different languages.');
            self::assertSame($idea->markets[0]['volume'], $idea->volume);
        }
        $keys = array_map(static fn(ArticleIdea $i): string => $i->marketKeys()[0] . ' ' . $i->mainKeyword . ' ' . $i->key, $ideas);
        self::assertContains('2840:en sunflower seeds ' . hash('sha256', 'sunflower seeds'), $keys, 'The first market keeps the plain key.');
        self::assertContains('2276:de sunflower seeds ' . hash('sha256', 'de:sunflower seeds'), $keys, 'Another language gets its own key.');
        self::assertCount(3, array_unique(array_map(static fn(ArticleIdea $i): string => $i->key, $ideas)));
    }

    public function testRelevanceAppliesPerMarket(): void
    {
        $relevantIn = ['2840:en' => static fn(string $k): bool => true, '2124:en' => static fn(string $k): bool => $k !== 'tomato cages'];
        $us = $this->build([self::row('tomato cages', 'https://rival.test/blog/tomato-cages', 500)], relevant: $relevantIn['2840:en'], market: '2840:en');
        $ca = $this->build([self::row('tomato cages', 'https://rival.ca.test/blog/tomato-cages', 400, domain: 'rival.ca.test')], relevant: $relevantIn['2124:en'], market: '2124:en');

        $ideas = IdeaBuilder::merge(['2840:en' => $us, '2124:en' => $ca]);

        self::assertCount(1, $ideas);
        self::assertSame(['2840:en'], $ideas[0]->marketKeys(), 'A search ruled out in one market adds nothing from it.');
        self::assertSame(500, $ideas[0]->volume);
    }

    /**
     * @param list<array{domain: string, found: bool, url: string, keyword: string, volume: ?int, difficulty: ?int, position: ?int, traffic: ?float, avg_rd: ?int}> $rows
     * @param array<string, array{url: string, position: ?float, impressions: int}> $own
     * @return list<ArticleIdea>
     */
    private function build(array $rows, array $own = [], ?\Closure $relevant = null, ?int $ownRd = null, ?string $market = null): array
    {
        $ctr = [2 => 0.15, 5 => 0.05, 9 => 0.02];

        return IdeaBuilder::build($rows, $own, $relevant ?? static fn(string $k): bool => true, static fn(int $position): float => $ctr[$position] ?? 0.01, $ownRd, [], $market);
    }

    /** @return array{domain: string, found: bool, url: string, keyword: string, volume: ?int, difficulty: ?int, position: ?int, traffic: ?float, avg_rd: ?int} */
    private static function row(string $keyword, string $url, int $volume, string $domain = 'rival.test', ?int $difficulty = 15, ?int $avgRd = 4, int $position = 3): array
    {
        return ['domain' => $domain, 'found' => false, 'url' => $url, 'keyword' => $keyword, 'volume' => $volume, 'difficulty' => $difficulty, 'position' => $position, 'traffic' => $volume * 0.1, 'avg_rd' => $avgRd];
    }
}
