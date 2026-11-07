<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Claims;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Claims\Claim;
use SeoControlCenter\Domain\Claims\ClaimFlags;

/** Automated checks of claim evidence (ADR-042): flags, never verdicts. */
final class ClaimFlagsTest extends TestCase
{
    public function testASourceAboutAnotherBreedersProductIsAScopeMismatch(): void
    {
        $claim = self::claim(claimScope: ['breeder' => 'Meadow Seeds', 'cultivar' => 'Brandywine'], sourceScope: ['breeder' => 'Hillside Farm', 'cultivar' => 'Brandywine #33']);
        $codes = array_column(ClaimFlags::of($claim, 'shop.test', [], '2026-10-23'), 'code');

        self::assertContains('breeder_mismatch', $codes);
        self::assertNotContains('cultivar_mismatch', $codes, '"Brandywine #33" names Brandywine: the cultivar matches, only the breeder differs.');
        self::assertSame(Claim::SUPPORTED, $claim->status, 'A flag never changes the verdict.');
    }

    public function testCompetitorsAndYourOwnSiteAreNotIndependentEvidence(): void
    {
        $competitor = self::claim(sourceUrl: 'https://www.rival.test/blog/brandywine');
        $own = self::claim(sourceUrl: 'https://shop.test/seeds/brandywine');
        self::assertContains('competitor', array_column(ClaimFlags::of($competitor, 'shop.test', ['rival.test'], '2026-10-23'), 'code'));
        self::assertContains('own_site', array_column(ClaimFlags::of($own, 'www.shop.test', ['rival.test'], '2026-10-23'), 'code'));
    }

    public function testMissingEvidenceAndOldChecksAreFlagged(): void
    {
        $flags = static fn(Claim $c): array => array_column(ClaimFlags::of($c, 'shop.test', [], '2026-10-23'), 'code');
        self::assertContains('no_source', $flags(self::claim(sourceUrl: null)));
        self::assertNotContains('no_source', $flags(self::claim(sourceUrl: null, status: Claim::UNVERIFIABLE)), 'Unverifiable says it already.');
        self::assertContains('no_passage', $flags(self::claim(passage: '')));
        self::assertContains('stale', $flags(self::claim(checkedOn: '2025-09-01')));
        self::assertContains('source_scope_unknown', $flags(self::claim(claimScope: ['cultivar' => 'Brandywine'], sourceScope: [])));
        self::assertSame([], $flags(self::claim()), 'A fully recorded, in-scope independent source raises nothing.');
    }

    public function testScopeValuesMatchIgnoringCaseAndPunctuation(): void
    {
        self::assertTrue(ClaimFlags::same('Meadow Seeds', 'Meadow Seeds'));
        self::assertTrue(ClaimFlags::same('Do-Si-Dos', 'do si dos #33'), 'A phenotype number is the same cultivar.');
        self::assertFalse(ClaimFlags::same('Brandywine', 'Brandywine Cake Ice'), 'Another word names another cultivar.');
        self::assertFalse(ClaimFlags::same('Brandywine', 'Brandywine Auto'), 'The sunflowering version is another product.');
        self::assertFalse(ClaimFlags::same('Brandywine', ''));
    }

    /**
     * @param array<string, string> $claimScope
     * @param array<string, string> $sourceScope
     */
    private static function claim(?string $sourceUrl = 'https://lab.test/report', string $status = Claim::SUPPORTED, string $passage = 'Table 2: NPK 24%', ?string $checkedOn = '2026-10-01', array $claimScope = ['breeder' => 'Meadow Seeds', 'cultivar' => 'Brandywine'], array $sourceScope = ['breeder' => 'Meadow Seeds', 'cultivar' => 'Brandywine']): Claim
    {
        return new Claim('0192f0a0-0000-7000-8000-000000000901', '0192f0a0-0000-7000-8000-000000000902', 'Brandywine reaches 24% NPK', 'Brandywine seeds page', $claimScope, $sourceUrl, 'Lab', $checkedOn, $passage, $sourceScope, $status, '', null, null, 1, '2026-10-23 00:00:00');
    }
}
