<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\QueryPatterns;

final class QueryPatternsTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function intents(): iterable
    {
        yield 'purchase' => ['Buy running shoes', 'transactional'];
        yield 'comparison' => ['best running shoes 2026', 'commercial'];
        yield 'versus' => ['asana vs trello', 'commercial'];
        yield 'definition' => ['what is a 401k', 'informational_simple'];
        yield 'quick fact' => ['how many ounces in a cup', 'informational_simple'];
        yield 'conversion' => ['5 kg to lbs', 'informational_simple'];
        yield 'plain how-to' => ['how to tie shoes', 'informational_simple'];
        yield 'how-to with depth' => ['how to choose running shoes step by step', 'informational_deep'];
        yield 'examples' => ['marketing plan examples', 'informational_deep'];
        yield 'tool' => ['mortgage calculator', 'tool'];
        yield 'tool with digital cue' => ['free logo maker', 'tool'];
        yield 'countable counter' => ['word counter', 'tool'];
        yield 'local' => ['plumber near me', 'local'];
        yield 'navigational' => ['facebook login', 'navigational'];
        yield 'nothing to go on' => ['red shoes', 'unknown'];
    }

    #[DataProvider('intents')]
    public function testIntentFromWording(string $query, string $intent): void
    {
        self::assertSame($intent, QueryPatterns::intent($query));
    }

    public function testProviderIntentOnlyDecidesAmbiguousWording(): void
    {
        self::assertSame('commercial', QueryPatterns::intent('red shoes', 'commercial'));
        self::assertSame('informational_deep', QueryPatterns::intent('red shoes', 'informational'));
        self::assertSame('transactional', QueryPatterns::intent('buy red shoes', 'informational'), 'Clear wording wins over the provider.');
    }

    public function testProductsAndPeopleAreNotTools(): void
    {
        foreach (['coffee maker', 'fitness tracker', 'wedding planner', 'garden tools', 'kitchen counter', 'home builder'] as $query) {
            self::assertNull(QueryPatterns::toolNoun($query), $query);
        }
        self::assertSame('calculator', QueryPatterns::toolNoun('Mortgage Calculators'));
        self::assertSame('calculator', QueryPatterns::toolNoun('bmi calc'));
        self::assertSame('analyzer', QueryPatterns::toolNoun('headline analyser'));
        self::assertSame('planner', QueryPatterns::toolNoun('free online meal planner'));
        self::assertSame('counter', QueryPatterns::toolNoun('character counter'));
    }

    public function testTokensReducePluralsButKeepWordsThatOnlyLookPlural(): void
    {
        self::assertSame(['running', 'shoe'], QueryPatterns::tokens('Running  SHOES'));
        self::assertSame(['box', 'glass', 'city'], QueryPatterns::tokens('boxes glasses cities'));
        self::assertSame(['news', 'analysis', 'business', 'series'], QueryPatterns::tokens('news analysis business series'));
    }

    public function testMentionsIgnoresOrderContainsDoesNot(): void
    {
        self::assertTrue(QueryPatterns::mentions('website audit for seo', 'seo audit'));
        self::assertFalse(QueryPatterns::contains('website audit for seo', 'seo audit'));
        self::assertTrue(QueryPatterns::contains('best trail running shoe', 'running shoes'));
        self::assertFalse(QueryPatterns::mentions('running', 'running shoes'));
        self::assertFalse(QueryPatterns::mentions('anything', ''));
    }

    public function testCareerSearches(): void
    {
        self::assertTrue(QueryPatterns::isCareer('seo specialist salary'));
        self::assertTrue(QueryPatterns::isCareer('shoe store jobs'));
        self::assertFalse(QueryPatterns::isCareer('job board software'));
    }
}
