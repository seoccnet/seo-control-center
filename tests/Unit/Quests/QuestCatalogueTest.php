<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Quests;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Config\Environment;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Domain\Quests\QuestCatalogue;
use SeoControlCenter\Domain\Quests\QuestEvidence;
use SeoControlCenter\Domain\Quests\QuestEvidenceSource;
use SeoControlCenter\Domain\Quests\StepKind;
use SeoControlCenter\Modules\Quests\GuideCatalogue;
use SeoControlCenter\Modules\Quests\Setup\FoundationsChecks;
use SeoControlCenter\Modules\Quests\ShowMeTargets;

final class QuestCatalogueTest extends TestCase
{
    public function testKeysAreUniqueStableSlugsAndChaptersAreNonEmpty(): void
    {
        $keys = array_map(static fn($q): string => $q->key, QuestCatalogue::quests());
        self::assertSame($keys, array_values(array_unique($keys)));
        $chapters = array_map(static fn($c): string => $c->key, QuestCatalogue::chapters());
        self::assertCount(6, $chapters);
        foreach (QuestCatalogue::chapters() as $chapter) {
            $quests = array_filter(QuestCatalogue::quests(), static fn($q): bool => $q->chapter === $chapter->number);
            self::assertNotSame([], $quests, $chapter->title . ' has no quests');
            self::assertSame(range(1, count($quests)), array_values(array_map(static fn($q): int => $q->order, $quests)), $chapter->title . ' order');
        }
        foreach (QuestCatalogue::quests() as $quest) {
            self::assertMatchesRegularExpression('/^[a-z]+\.[a-z0-9-]+$/', $quest->key);
            self::assertNotNull(QuestCatalogue::chapter($quest->chapter));
            self::assertNotSame([], $quest->steps, $quest->key);
            self::assertGreaterThanOrEqual(1, count($quest->learn), $quest->key);
            self::assertLessThanOrEqual(3, count($quest->learn), $quest->key);
            self::assertNotSame([], $quest->tips, $quest->key);
            self::assertNotSame([], $quest->mistakes, $quest->key);
            self::assertGreaterThan(0, $quest->minutes);
            $steps = array_map(static fn($s): string => $s->key, $quest->steps);
            self::assertSame($steps, array_values(array_unique($steps)), $quest->key);
            foreach ($quest->steps as $step) {
                self::assertMatchesRegularExpression('/^[a-z0-9-]{1,30}$/', $step->key);
                self::assertNotSame('', $step->key);
                // Learning steps are state-based: each names the evidence that completes it.
                self::assertSame($quest->live, $step->signals === [], $quest->key . '/' . $step->key);
            }
            self::assertStringNotContainsStringIgnoringCase('SCC', $quest->title . $quest->story);
            self::assertStringNotContainsStringIgnoringCase('upgrade', $quest->title . $quest->story . implode('', $quest->tips));
        }
    }

    public function testFoundationsMatchesTheSetupChecksInOrder(): void
    {
        $live = array_values(array_filter(QuestCatalogue::quests(), static fn($q): bool => $q->live));
        self::assertSame([
            FoundationsChecks::CRON, FoundationsChecks::WEBSITE, FoundationsChecks::SEARCH_CONSOLE,
            FoundationsChecks::DATAFORSEO, FoundationsChecks::KEYWORDS, FoundationsChecks::RANK_TRACKING, FoundationsChecks::RESULTS,
        ], array_map(static fn($q): string => $q->key, $live));
        foreach ($live as $quest) {
            self::assertSame(1, $quest->chapter);
        }
        self::assertSame(StepKind::Wait, QuestCatalogue::find(FoundationsChecks::RESULTS)?->steps[0]->kind);
    }

    public function testGuideLinksPointToPackagedChapters(): void
    {
        $guide = new GuideCatalogue(dirname(__DIR__, 3) . '/resources/guide/catalog.json');
        self::assertNotNull($guide->title('26'), 'The Quest Log guide chapter is packaged.');
        foreach (QuestCatalogue::quests() as $quest) {
            self::assertNotNull($guide->title($quest->guide), $quest->key . ' links to missing guide chapter ' . $quest->guide);
        }
    }

    public function testShowMeTargetsAreNamedRoutesAndEverySignalHasAProductionSource(): void
    {
        $container = self::app();
        $targets = $container->make(ShowMeTargets::class);
        foreach (QuestCatalogue::quests() as $quest) {
            foreach ($quest->steps as $step) {
                if ($step->showMe !== null) {
                    self::assertTrue($targets->valid($step->showMe), $quest->key . '/' . $step->key . ' → ' . $step->showMe->route);
                }
            }
        }
        $evidence = $container->make(QuestEvidence::class);
        self::assertInstanceOf(QuestEvidenceSource::class, $evidence);
        $missing = array_diff(QuestCatalogue::evidenceSignals(), $evidence->signals());
        self::assertSame([], array_values($missing));
    }

    private static function app(): Container
    {
        /** @var \Closure(string, Environment): Container $bootstrap */
        $bootstrap = require dirname(__DIR__, 3) . '/bootstrap/app.php';

        return $bootstrap(dirname(__DIR__, 3), new Environment([
            'APP_ENV' => 'testing',
            'APP_KEYS' => '1:' . base64_encode(str_repeat("\x42", 32)),
            'DB_DATABASE' => 'unused_in_unit_tests',
        ]));
    }
}
