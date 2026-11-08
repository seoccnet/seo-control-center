<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\ArticleIdeas;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\PlanProposer;
use SeoControlCenter\Domain\ArticleIdeas\TopicPlan;

final class PlanProposerTest extends TestCase
{
    public function testSupportingIdeasSearchesAndPagesShareTheSubject(): void
    {
        $pillar = self::idea('brandywine cultivar', ['brandywine cultivar', 'brandywine pepper'], 900.0);
        $ideas = [
            $pillar,
            self::idea('blue brandywine 41', ['blue brandywine 41', 'blue brandywine cultivar'], 300.0),
            self::idea('brandywine vs roma', ['brandywine vs roma'], 500.0),
            self::idea('san marzano cultivar', ['san marzano cultivar'], 800.0),
            self::idea('black krim cultivar', ['black krim cultivar'], 700.0),
            self::idea('green zebra cultivar', ['green zebra cultivar'], 100.0),
            ...array_map(static fn(string $s): ArticleIdea => self::idea($s . ' cultivar', [$s . ' cultivar'], 50.0), ['ace 47', 'cherokee purple', 'early girl', 'celebrity', 'acapulco gold', 'beefsteak']),
        ];
        $members = PlanProposer::propose($pillar, $ideas, [
            ['query' => 'Brandywine seeds', 'url' => 'https://shop.test/seeds/brandywine', 'impressions' => 400],
            ['query' => 'brandywine seeds', 'url' => 'https://shop.test/blog/brandywine', 'impressions' => 50],
            ['query' => 'brandywine pepper', 'url' => 'https://shop.test/seeds/brandywine', 'impressions' => 90],
            ['query' => 'san marzano seeds', 'url' => 'https://shop.test/seeds/sour-diesel', 'impressions' => 900],
            ...self::pepperQueries('cultivar'),
        ]);
        $by = static fn(string $type): array => array_values(array_filter($members, static fn(array $m): bool => $m['type'] === $type));

        self::assertSame(['brandywine vs roma', 'blue brandywine 41'], array_column($by(TopicPlan::IDEA), 'label'), '"cultivar" is in far more searches than "brandywine": only "brandywine" defines the subject.');
        self::assertSame([['brandywine seeds', 450, false]], array_map(static fn(array $m): array => [$m['key'], $m['volume'], $m['kept']], $by(TopicPlan::SEARCH)), 'Not covered by an idea; proposed, not kept.');
        self::assertSame(['https://shop.test/seeds/brandywine', 'https://shop.test/blog/brandywine'], array_column($by(TopicPlan::PAGE), 'url'));
        self::assertSame('/seeds/brandywine', $by(TopicPlan::PAGE)[0]['label']);
    }

    public function testTheSubjectIsThePillarsRarestWords(): void
    {
        // 0.29.0 live check, finding 16: "mold on pepper" proposed every pepper idea, query and page.
        $corpus = ['mold on chili', 'molds on blossoms', 'paste vs slicer plant', 'slicer', 'paste', ...array_column(self::pepperQueries('pepper plant'), 'query')];

        self::assertSame(['mold'], PlanProposer::subject('mold on pepper', $corpus));
        self::assertSame(['paste', 'slicer'], PlanProposer::subject('paste vs slicer plant', $corpus), 'Both about as rare; "plant" is common.');
        self::assertSame(['pepper', 'plant'], PlanProposer::subject('pepper plant', ['pepper', 'pepper plant care', 'pepper plant light', 'plant food']), 'Equally common: both count.');
        self::assertTrue(PlanProposer::about('Molds on blossoms', ['mold']), 'Plurals folded the same way as searches.');
        self::assertFalse(PlanProposer::about('strawberry pepper', ['mold']));
        self::assertSame([], PlanProposer::subject('the', $corpus));
    }

    public function testMoldOnWeedProposesOnlyMold(): void
    {
        $pillar = self::idea('mold on pepper', ['mold on pepper', 'moldy blossoms'], 900.0);
        $ideas = [$pillar, self::idea('mold on chili', ['mold on chili'], 400.0), self::idea('bonsai pepper', ['bonsai pepper'], 800.0), self::idea('sprayed pepper', ['sprayed pepper'], 700.0)];
        $members = PlanProposer::propose($pillar, $ideas, [
            ['query' => 'white mold on pepper', 'url' => 'https://shop.test/blog/mold', 'impressions' => 80],
            ['query' => 'strawberry pepper', 'url' => 'https://shop.test/tastes/strawberry', 'impressions' => 290],
            ['query' => 'free pepper seeds', 'url' => 'https://shop.test/giveaways', 'impressions' => 142],
            ...self::pepperQueries('pepper'),
        ]);

        self::assertSame([['idea', 'mold on chili'], ['search', 'white mold on pepper'], ['page', '/blog/mold']], array_map(static fn(array $m): array => [$m['type'], $m['label']], $members));
    }

    /**
     * Search Console rows that use a site-wide word, as a seed shop's do.
     *
     * @return list<array{query: string, url: string, impressions: int}>
     */
    private static function pepperQueries(string $word): array
    {
        return array_map(static fn(string $name): array => ['query' => $name . ' ' . $word, 'url' => 'https://shop.test/tastes/' . str_replace(' ', '-', $name), 'impressions' => 5], ['banana', 'grape', 'cherry', 'tropical', 'spicy', 'sweet', 'fruity', 'lemon', 'mint', 'berry', 'orange', 'pine']);
    }

    public function testSupportingIdeasShareALanguageOfThePillarsMarkets(): void
    {
        $pillar = self::idea('brandywine', ['brandywine'], 900.0)->inMarket('2840:en');
        $members = PlanProposer::propose($pillar, [
            $pillar,
            self::idea('brandywine seeds', ['brandywine seeds'], 500.0)->inMarket('2124:en'),
            self::idea('brandywine samen', ['brandywine samen'], 400.0)->inMarket('2276:de'),
            self::idea('brandywine vs roma', ['brandywine vs roma'], 300.0),
        ], []);

        self::assertSame(['brandywine seeds', 'brandywine vs roma'], array_column(array_values(array_filter($members, static fn(array $m): bool => $m['type'] === TopicPlan::IDEA)), 'label'), 'Another language is left out; ideas without markets still count.');
    }

    /** @param list<string> $keywords */
    private static function idea(string $main, array $keywords, float $score): ArticleIdea
    {
        return new ArticleIdea(hash('sha256', $main), ArticleIdea::NEW, $main, 1000, array_map(static fn(string $k): array => ['keyword' => $k, 'volume' => 100, 'difficulty' => 10], $keywords), [], null, [
            'traffic' => ['low' => 1, 'expected' => 2, 'high' => 3],
            'difficulty' => ['level' => 'low', 'reasons' => []],
            'backlinks' => null,
            'months' => ['low' => 1, 'high' => 3, 'basis' => 'x'],
        ], $score, []);
    }
}
