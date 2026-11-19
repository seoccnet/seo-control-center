<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Snippets;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Snippets\TitleRewrite;

/** Own title against the title Google showed (1.7.0). */
final class TitleRewriteTest extends TestCase
{
    public function testKinds(): void
    {
        self::assertSame(TitleRewrite::KEPT, TitleRewrite::compare('Red Shoes &amp; Boots | Acme', 'Red shoes & boots | Acme')['kind']);
        self::assertSame(TitleRewrite::KEPT, TitleRewrite::compare('Red shoes – Acme', 'Red shoes - Acme')['kind'], 'Separators count as one.');
        self::assertSame(TitleRewrite::CUT, TitleRewrite::compare('Red running shoes for long distances and marathons | Acme', 'Red running shoes for long distances ...')['kind']);
        self::assertSame(TitleRewrite::CUT, TitleRewrite::compare('Red running shoes for marathons', 'Red running shoes for…')['kind']);
        self::assertSame(TitleRewrite::SITE_NAME, TitleRewrite::compare('Red shoes | Acme', 'Red shoes - Acme Shop')['kind']);
        self::assertSame(TitleRewrite::SITE_NAME, TitleRewrite::compare('Red shoes', 'Red shoes | Acme')['kind'], 'Site name added.');
        self::assertSame(TitleRewrite::SITE_NAME, TitleRewrite::compare('Red shoes | Acme', 'Acme: Red shoes')['kind'], 'Site name moved to the front.');
        self::assertSame(TitleRewrite::MISSING, TitleRewrite::compare(null, 'Acme')['kind']);
        self::assertSame(TitleRewrite::MISSING, TitleRewrite::compare('  ', 'Acme')['kind']);

        $rewritten = TitleRewrite::compare('Home', 'Red running shoes – Acme');
        self::assertSame(TitleRewrite::REWRITTEN, $rewritten['kind']);
        self::assertSame(0.0, $rewritten['overlap']);
        $partly = TitleRewrite::compare('Buy cheap red shoes shoes shoes', 'Red shoes for children | Acme');
        self::assertSame(TitleRewrite::REWRITTEN, $partly['kind']);
        self::assertSame(0.4, $partly['overlap'], '"red" and "shoes" of five words.');
        self::assertSame(TitleRewrite::REWRITTEN, TitleRewrite::compare('Grüne Schuhe', 'Grüne Stiefel')['kind']);
        self::assertSame(0.5, TitleRewrite::compare('Grüne Schuhe', 'Grüne Stiefel')['overlap']);
    }

    public function testHyphenatedWordsAreNotSeparators(): void
    {
        self::assertSame(TitleRewrite::REWRITTEN, TitleRewrite::compare('Anti-slip mats', 'Anti mats')['kind']);
        self::assertSame('anti-slip mats | acme', TitleRewrite::normalize('Anti-slip  Mats — ACME'));
    }
}
