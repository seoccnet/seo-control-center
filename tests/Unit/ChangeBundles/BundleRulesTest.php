<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\ChangeBundles;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ChangeBundles\BundleRules;

/** Change bundles (ADR-042, 0.40.0): record, verify and guard. */
final class BundleRulesTest extends TestCase
{
    private const array BASELINE = ['status' => 200, 'title' => 'Mold on pepper', 'description' => 'Old', 'h1s' => 'Mold on pepper', 'h1_count' => 1, 'canonical' => 'https://s.test/mold', 'noindex' => false, 'html' => true, 'url' => 'https://s.test/mold', 'words' => 1200, 'text' => 'abc'];

    public function testTheLivePageChangedAfterApprovalBlocksTheDeployment(): void
    {
        $edits = [['field' => 'title', 'before' => 'Mold on pepper', 'after' => 'Mold on pepper: spot, prevent, treat']];
        $hash = BundleRules::hash('Title', 'Weak CTR', '', 'https://s.test/mold', $edits, 'More clicks', 'Title live', self::BASELINE);
        $changed = [...self::BASELINE, 'title' => 'Someone else edited this', 'words' => 1250];
        $differences = BundleRules::differences(self::BASELINE, $changed);
        self::assertSame(['title' => ['before' => 'Mold on pepper', 'after' => 'Someone else edited this'], 'words' => ['before' => '1200', 'after' => '1250']], $differences);
        $guard = ['checked_at' => '2026-10-24 12:00:00', 'matches' => false, 'changes' => $differences];
        self::assertSame('The live page changed since the approval (Title, Word count). Capture a new baseline, review the edits against it and approve again.', BundleRules::deploymentBlock('approved', $hash, $hash, '2026-10-24 10:00:00', $guard, '2026-10-24 13:00:00'));

        $ok = ['checked_at' => '2026-10-24 12:00:00', 'matches' => true, 'changes' => []];
        self::assertNull(BundleRules::deploymentBlock('approved', $hash, $hash, '2026-10-24 10:00:00', $ok, '2026-10-24 13:00:00'));
        self::assertSame('Approve the bundle first.', BundleRules::deploymentBlock('draft', $hash, null, null, $ok, '2026-10-24 13:00:00'));
        self::assertSame('The bundle changed after its approval. Approve it again.', BundleRules::deploymentBlock('approved', $hash, 'other', '2026-10-24 10:00:00', $ok, '2026-10-24 13:00:00'));
        self::assertStringStartsWith('Check the live page first', (string) BundleRules::deploymentBlock('approved', $hash, $hash, '2026-10-24 10:00:00', null, '2026-10-24 13:00:00'));
        self::assertStringStartsWith('Check the live page first', (string) BundleRules::deploymentBlock('approved', $hash, $hash, '2026-10-24 12:30:00', $ok, '2026-10-24 13:00:00'), 'A check from before the approval does not count.');
        self::assertStringStartsWith('The last live check is older than 24 hours', (string) BundleRules::deploymentBlock('approved', $hash, $hash, '2026-10-24 10:00:00', $ok, '2026-10-25 13:00:00'));
    }

    public function testTheHashCoversContentAndBaseline(): void
    {
        $edits = [['field' => 'title', 'before' => 'a', 'after' => 'b']];
        $hash = BundleRules::hash('T', 'P', 'E', 'https://s.test/', $edits, 'X', 'V', self::BASELINE);
        self::assertSame(64, strlen($hash));
        self::assertNotSame($hash, BundleRules::hash('T', 'P', 'E', 'https://s.test/', [['field' => 'title', 'before' => 'a', 'after' => 'c']], 'X', 'V', self::BASELINE));
        self::assertNotSame($hash, BundleRules::hash('T', 'P', 'E', 'https://s.test/', $edits, 'X', 'V', [...self::BASELINE, 'words' => 1]));
        self::assertSame([], BundleRules::differences(self::BASELINE, self::BASELINE));
        self::assertSame(['text' => ['before' => 'as approved', 'after' => 'changed']], BundleRules::differences(self::BASELINE, [...self::BASELINE, 'text' => 'xyz']));
    }

    public function testVerificationReadsEditsBackFromTheLivePage(): void
    {
        $edits = [
            ['field' => 'title', 'before' => 'Mold on pepper', 'after' => 'Mold on pepper: spot &amp; treat'],
            ['field' => 'description', 'before' => 'Old', 'after' => 'New description'],
            ['field' => 'h1', 'before' => '', 'after' => 'How to spot mold'],
            ['field' => 'canonical', 'before' => '', 'after' => 'https://s.test/mold/'],
            ['field' => 'noindex', 'before' => 'no', 'after' => 'no'],
            ['field' => 'content', 'before' => '', 'after' => 'New FAQ section'],
            ['field' => 'redirect', 'before' => '', 'after' => '/old to /mold'],
        ];
        $live = [...self::BASELINE, 'title' => 'Mold on pepper:  spot & treat', 'description' => 'Still old', 'h1s' => "Mold on pepper\nHow to spot mold", 'canonical' => '/mold', 'text' => 'new'];
        $checks = BundleRules::verify($edits, [true, true, true, true, true, true, false], $live, self::BASELINE);
        self::assertSame(['matched', 'mismatch', 'matched', 'matched', 'matched', 'manual', 'not_applied'], array_column($checks, 'status'));
        self::assertSame('Still old', $checks[1]['live']);
        self::assertSame('the body text changed since the baseline', $checks[5]['live']);

        self::assertSame('title', BundleRules::changeKind([$edits[0], $edits[1]]));
        self::assertSame('content', BundleRules::changeKind([$edits[0], $edits[5]]));
        self::assertSame('technical', BundleRules::changeKind([$edits[3], $edits[6]]));
        self::assertSame('other', BundleRules::changeKind([$edits[0], $edits[3]]));
    }
}
