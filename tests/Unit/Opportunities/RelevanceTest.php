<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Opportunities\Relevance;

final class RelevanceTest extends TestCase
{
    public function testRulesAndTeamOverrides(): void
    {
        $profile = new BusinessContext('ecommerce', 'sales', offerings: ['running shoes', 'trail shoes'], exclusions: ['free', 'diy']);
        $relevance = new Relevance($profile, ['free running shoes' => 2, 'running shoes history' => 0]);

        self::assertSame(['verdict' => 'relevant', 'reason' => 'mentions “running shoes”, which you offer', 'source' => 'rules'], $relevance->of('Best Running Shoes'));
        self::assertSame('not_relevant', $relevance->of('free shoe patterns')['verdict']);
        self::assertStringContainsString('“free”', $relevance->of('free shoe patterns')['reason']);
        self::assertSame('not_relevant', $relevance->of('shoe store jobs')['verdict'], 'Job searches never fit unless the business offers jobs.');
        self::assertSame('unsure', $relevance->of('marathon training plan')['verdict'], 'Naming no offering is unsure, not irrelevant.');
        self::assertSame(['verdict' => 'relevant', 'reason' => 'your team rated it 2/3', 'source' => 'team'], $relevance->of('free running shoes'), 'A team rating wins over an excluded term.');
        self::assertSame('not_relevant', $relevance->of('running shoes history')['verdict'], 'Rating 0 marks it not relevant even when it names an offering.');
        self::assertNull($relevance->clash('trail shoes'));
        self::assertNotNull($relevance->clash('diy shoe repair'));
    }

    public function testNothingIsCheckedWithoutAProfileOrOfferings(): void
    {
        self::assertSame('unchecked', (new Relevance(null))->of('anything')['verdict']);
        self::assertNull((new Relevance(null))->clash('free jobs'));
        self::assertSame('unchecked', (new Relevance(new BusinessContext('ecommerce', 'sales')))->of('red shoes')['verdict']);
    }

    public function testExclusionLimitIsHundredAndOfferingsThirty(): void
    {
        $terms = static fn(int $n, string $prefix): string => implode("\n", array_map(static fn(int $i): string => $prefix . ' ' . $i, range(1, $n)));
        self::assertCount(100, BusinessContext::fromInput(['model' => 'ecommerce', 'goal' => 'sales', 'exclusions' => $terms(100, 'never')])->exclusions);
        try {
            BusinessContext::fromInput(['model' => 'ecommerce', 'goal' => 'sales', 'exclusions' => $terms(101, 'never')]);
            self::fail('101 exclusions must be refused.');
        } catch (\SeoControlCenter\Domain\Shared\ValidationException $e) {
            self::assertSame(['exclusions' => 'Enter at most 100 terms.'], $e->errors());
        }
        try {
            BusinessContext::fromInput(['model' => 'ecommerce', 'goal' => 'sales', 'offerings' => $terms(31, 'offer')]);
            self::fail('31 offerings must be refused.');
        } catch (\SeoControlCenter\Domain\Shared\ValidationException $e) {
            self::assertSame(['offerings' => 'Enter at most 30 terms.'], $e->errors());
        }
    }

    public function testMarkets(): void
    {
        $profile = BusinessContext::fromInput(['model' => 'ecommerce', 'goal' => 'sales', 'market_location' => ['2276', '2040', '', '9999'], 'market_language' => ['de', 'de', 'en', 'xx']]);
        self::assertSame(['2276:de', '2040:de'], $profile->markets, 'Empty and unsupported rows are skipped.');
        self::assertTrue($profile->servesMarket(new KeywordLocale(2276, 'de')));
        self::assertFalse($profile->servesMarket(new KeywordLocale(2276, 'en')));
        self::assertTrue((new BusinessContext('ecommerce', 'sales'))->servesMarket(new KeywordLocale(2840, 'en')), 'No markets set: nothing is outside.');
        self::assertSame('Germany · German', KeywordLocale::fromKey('2276:de')?->label());
        self::assertNull(KeywordLocale::fromKey('1:zz'));
    }
}
