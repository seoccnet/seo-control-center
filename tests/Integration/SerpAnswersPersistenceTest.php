<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;

/** Featured snippet holders and "People also ask" questions (1.5.0) on the real engine. */
final class SerpAnswersPersistenceTest extends FullStackTestCase
{
    public function testLatestCheckDecidesAndQuestionsRoundTrip(): void
    {
        $owner = $this->createUser('answers@acme.test', 'Answers');
        self::assertNotNull($owner['workspace']);
        $ws = $owner['workspace']->id;
        $publicId = $this->createProject($this->browserFor('answers@acme.test'), 'Answers', 'acme.test');
        $project = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $this->app->make(KeywordRepository::class)->add($project, ['red shoes', 'blue shoes', 'green shoes'], KeywordLocale::default(), $owner['user']->id);
        [$red, $blue, $green] = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM project_keywords WHERE project_id = ? ORDER BY id', [$project]), 'id'));
        $rankings = $this->app->make(RankingRepository::class);
        $today = gmdate('Y-m-d');
        $before = gmdate('Y-m-d', strtotime('-7 days'));

        $rankings->storeSnapshot(new RankSnapshot($red, $project, SerpDevice::Desktop, $before, 9, 9, null, ['featured_snippet'], [], 'old.test'));
        $rankings->storeSnapshot(new RankSnapshot($red, $project, SerpDevice::Desktop, $today, 4, 5, 'https://acme.test/red', ['featured_snippet', 'people_also_ask'], [], 'www.rival.test', ['Do red shoes stretch?', 'Wie fallen rote Schuhe aus?']));
        $rankings->storeSnapshot(new RankSnapshot($blue, $project, SerpDevice::Desktop, $before, 2, 2, null, ['featured_snippet'], [], 'rival.test'));
        $rankings->storeSnapshot(new RankSnapshot($blue, $project, SerpDevice::Desktop, $today, 2, 2, null, [], []));
        // A check from before 1.5.0: a snippet was shown, its holder is unknown.
        $rankings->storeSnapshot(new RankSnapshot($green, $project, SerpDevice::Desktop, $today, null, null, null, ['featured_snippet'], []));
        $rankings->storeSnapshot(new RankSnapshot($green, $project, SerpDevice::Mobile, $today, 1, 1, null, [], [], 'acme.test'));

        $answers = $rankings->answers($project, $ws, SerpDevice::Desktop, 10);

        self::assertSame(['green shoes', 'red shoes'], array_map(static fn($a): string => $a->keyword, $answers));
        [$greenRow, $redRow] = $answers;
        self::assertTrue($greenRow->hasSnippet);
        self::assertNull($greenRow->snippetDomain);
        self::assertSame('www.rival.test', $redRow->snippetDomain);
        self::assertSame(['Do red shoes stretch?', 'Wie fallen rote Schuhe aus?'], $redRow->questions);
        self::assertSame($today, $redRow->checkedOn);
        self::assertTrue($redRow->isSnippetOpportunity('acme.test'));

        // A check of the same day again replaces the stored answers.
        $rankings->storeSnapshot(new RankSnapshot($red, $project, SerpDevice::Desktop, $today, 4, 5, null, [], []));
        self::assertSame(['green shoes'], array_map(static fn($a): string => $a->keyword, $rankings->answers($project, $ws, SerpDevice::Desktop, 10)));
    }
}
