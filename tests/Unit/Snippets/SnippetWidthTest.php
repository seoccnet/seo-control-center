<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Snippets;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Snippets\SnippetWidth;

/** Snippet widths in pixels (1.6.0). */
final class SnippetWidthTest extends TestCase
{
    public function testWidthsFollowArial(): void
    {
        self::assertSame(11.1, SnippetWidth::width('a', 20));
        self::assertSame(4.4, SnippetWidth::width('i', 20));
        self::assertSame(SnippetWidth::width('a b', 14), SnippetWidth::width("  a \n b ", 14), 'Whitespace collapses as in HTML.');
        self::assertSame(SnippetWidth::width('e', 20), SnippetWidth::width('é', 20), 'Accented letters measure like their base letter.');
        self::assertSame(20.0, SnippetWidth::width('日', 20));
    }

    public function testSameLengthDifferentWidth(): void
    {
        $narrow = str_repeat('il', 30);
        $wide = str_repeat('WM', 30);
        self::assertSame(60, mb_strlen($narrow));
        self::assertTrue(SnippetWidth::title($narrow)['fits'], 'Sixty narrow characters fit.');
        self::assertFalse(SnippetWidth::title($wide)['fits'], 'Sixty capitals do not.');
    }

    public function testTruncationKeepsWholeWords(): void
    {
        $title = 'Running shoes for women and men – the complete buying guide for every budget in 2026';
        $shown = SnippetWidth::title($title)['shown'];
        self::assertStringEndsWith(' ...', $shown);
        self::assertStringStartsWith('Running shoes for women', $shown);
        self::assertLessThanOrEqual(SnippetWidth::TITLE_PX, SnippetWidth::width($shown, SnippetWidth::TITLE_FONT));
        self::assertTrue(str_starts_with($title, substr($shown, 0, -4)), 'Only whole words are kept.');
        self::assertSame('Short title', SnippetWidth::title('Short   title')['shown']);
        self::assertStringEndsWith(' ...', SnippetWidth::truncate(str_repeat('W', 80), 600, 20), 'A single long word is cut by characters.');
    }
}
