<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Pdf;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Pdf\PdfDocument;
use SeoControlCenter\Core\Pdf\TrueTypeFont;
use SeoControlCenter\Tests\Support\PdfText;

/**
 * The in-house PDF writer (ADR-033): embedded subset fonts with Unicode
 * text, cross-reference offsets, links and images.
 */
final class PdfDocumentTest extends TestCase
{
    private const string FONTS = __DIR__ . '/../../../resources/fonts';

    private function document(): PdfDocument
    {
        $pdf = new PdfDocument('Report – Hodžić', 'SEO Control Center', new \DateTimeImmutable('2026-10-09 12:00:00 UTC'));
        $pdf->addFont('regular', TrueTypeFont::fromFile(self::FONTS . '/IBMPlexSans-Regular.ttf'));

        return $pdf;
    }

    public function testUnicodeTextIsEmbeddedSearchableAndPositioned(): void
    {
        $pdf = $this->document();
        $page = $pdf->addPage();
        $pdf->text($page, 40, 60, 'Ana Hodžić – Čćšđž € → 12.5 %', 'regular', 12);
        $pdf->text($pdf->addPage(), 40, 60, 'Second page', 'regular', 12);
        $bytes = $pdf->output();

        self::assertStringStartsWith("%PDF-1.7\n", $bytes);
        $read = new PdfText($bytes);
        self::assertSame(['Ana Hodžić – Čćšđž € → 12.5 %', 'Second page'], $read->pages());
        self::assertMatchesRegularExpression('/^[A-Z]{6}\+IBMPlexSans-Regular$/', $read->fontNames()[0]);
        self::assertStringContainsString('/Title <FEFF', $bytes, 'Non-ASCII titles are UTF-16.');
        self::assertStringContainsString('/CreationDate (D:20261009120000Z)', $bytes);

        // Every cross-reference entry points at its object.
        self::assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $bytes, $m));
        $xref = (int) $m[1];
        self::assertSame('xref', substr($bytes, $xref, 4));
        preg_match_all('/^(\d{10}) 00000 n $/m', substr($bytes, $xref), $entries);
        foreach ($entries[1] as $i => $offset) {
            self::assertStringStartsWith(($i + 1) . ' 0 obj', substr($bytes, (int) $offset, 20));
        }
    }

    public function testWidthsWrapAndMissingGlyphsDoNotBreakTheFont(): void
    {
        $pdf = $this->document();
        self::assertEqualsWithDelta(2 * $pdf->width('a', 'regular', 10), $pdf->width('aa', 'regular', 10), 0.001);
        self::assertGreaterThan($pdf->width('i', 'regular', 10), $pdf->width('W', 'regular', 10));
        $page = $pdf->addPage();
        $pdf->text($page, 40, 60, "Tab\there ▲ \u{1F600}", 'regular', 10);
        $read = new PdfText($pdf->output());
        self::assertSame('Tab here ? ?', $read->pages()[0], 'Control characters become spaces; glyphs the font lacks fall back to .notdef.');
    }

    public function testOnlyWebLinksBecomeAnnotations(): void
    {
        $pdf = $this->document();
        $page = $pdf->addPage();
        $pdf->link($page, 40, 40, 100, 12, 'https://example.test/report?a=1&b=(2)');
        $pdf->link($page, 40, 60, 100, 12, 'javascript:alert(1)');
        $pdf->link($page, 40, 80, 100, 12, "https://example.test/\nx");
        $read = new PdfText($pdf->output());
        self::assertSame(['https://example.test/report?a=1&b=(2)'], $read->links());
    }

    public function testJpegAndOpaquePngAreEmbeddedButTransparentPngIsNot(): void
    {
        $pdf = $this->document();
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png = static fn(int $colorType, string $extra = ''): string => "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 4, 2, 8, $colorType, 0, 0, 0)) . $extra . $chunk('IDAT', (string) gzcompress(str_repeat("\0" . str_repeat("\x10", 4 * ($colorType === 6 ? 4 : 3)), 2))) . $chunk('IEND', '');

        $opaque = $pdf->addImage($png(2));
        self::assertSame(['index' => 0, 'width' => 4, 'height' => 2], $opaque);
        self::assertNull($pdf->addImage($png(6)), 'RGBA needs decoding; left out.');
        self::assertNull($pdf->addImage($png(2, $chunk('tRNS', "\0\0\0\0\0\0"))), 'Colour-key transparency is left out.');
        self::assertNull($pdf->addImage('not an image'));
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', true);
        self::assertIsString($jpeg);
        self::assertSame(1, $pdf->addImage($jpeg)['index'] ?? null);

        $page = $pdf->addPage();
        $pdf->image($page, 0, 40, 40, 40, 20);
        $bytes = $pdf->output();
        self::assertSame(2, (new PdfText($bytes))->imageCount());
        self::assertStringContainsString('/Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns 4 >>', $bytes);
        self::assertStringContainsString('/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /DCTDecode', $bytes);
    }

    public function testSubsetKeepsGlyphNumbersAndOnlyUsedOutlines(): void
    {
        $font = TrueTypeFont::fromFile(self::FONTS . '/IBMPlexSans-Regular.ttf');
        $a = $font->glyph(0x61);
        $cAcute = $font->glyph(0x107);
        self::assertGreaterThan(0, $a);
        self::assertGreaterThan(0, $cAcute);
        $subset = $font->subset([$a, $cAcute]);
        self::assertLessThan(filesize(self::FONTS . '/IBMPlexSans-Regular.ttf') / 4, strlen($subset));

        $u16 = static fn(string $bytes, int $at): int => (int) hexdec(bin2hex(substr($bytes, $at, 2)));
        $u32 = static fn(string $bytes, int $at): int => (int) hexdec(bin2hex(substr($bytes, $at, 4)));
        $tables = [];
        for ($i = 0, $count = $u16($subset, 4); $i < $count; ++$i) {
            $tables[substr($subset, 12 + 16 * $i, 4)] = substr($subset, $u32($subset, 12 + 16 * $i + 8), $u32($subset, 12 + 16 * $i + 12));
        }
        self::assertSame(['cvt ', 'fpgm', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'prep'], array_values(array_intersect(['cvt ', 'fpgm', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'prep'], array_keys($tables))));
        self::assertSame($font->numGlyphs, $u16($tables['maxp'], 4), 'Glyph numbering is unchanged.');
        self::assertSame(1, $u16($tables['head'], 50), 'Long loca offsets.');
        $loca = array_map(static fn(string $word): int => $u32($word, 0), str_split($tables['loca'], 4));
        self::assertCount($font->numGlyphs + 1, $loca);
        self::assertGreaterThan(0, $loca[$a + 1] - $loca[$a], 'Used glyphs keep their outline.');
        self::assertGreaterThan(0, $loca[1] - $loca[0], '.notdef is kept.');
        $empty = 0;
        for ($g = 1; $g < $font->numGlyphs; ++$g) {
            $empty += $loca[$g + 1] === $loca[$g] ? 1 : 0;
        }
        self::assertGreaterThan($font->numGlyphs - 10, $empty, 'Unused glyphs are emptied (composite parts of ć stay).');

        $sum = 0;
        foreach (str_split($subset . str_repeat("\0", (4 - strlen($subset) % 4) % 4), 4) as $word) {
            $sum = ($sum + $u32($word, 0)) & 0xFFFFFFFF;
        }
        self::assertSame(0xB1B0AFBA, $sum, 'Whole-font checksum.');
    }

    public function testRejectsFilesThatAreNotTrueTypeFonts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrueTypeFont::fromFile(__FILE__);
    }
}
