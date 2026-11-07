<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Csv;
use SeoControlCenter\Core\Http\ResponseFactory;

final class CsvTest extends TestCase
{
    public function testEncodesWithBomCommasAndCrlf(): void
    {
        $csv = Csv::encode([['Name', 'Value'], ['a, "b"', 3], ["line\nbreak", null]]);

        self::assertSame("\u{FEFF}Name,Value\r\n\"a, \"\"b\"\"\",3\r\n\"line\nbreak\",\r\n", $csv);
    }

    public function testFormatsScalars(): void
    {
        self::assertSame("\u{FEFF}1.5,0.1235,2,-4,yes,no\r\n", Csv::encode([[1.5, 0.12345, 2.0, -4, true, false]]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formulas(): iterable
    {
        yield 'equals' => ['=SUM(A1)'];
        yield 'plus' => ['+1'];
        yield 'minus' => ['-1+2'];
        yield 'at' => ['@cmd'];
        yield 'tab' => ["\tx"];
        yield 'carriage return' => ["\rx"];
    }

    #[DataProvider('formulas')]
    public function testEscapesFormulaText(string $value): void
    {
        $cells = str_getcsv(substr(Csv::encode([[$value]]), 3, -2), ',', '"', '');

        self::assertSame(["'" . $value], $cells);
    }

    public function testFileNameIsASafeSlug(): void
    {
        self::assertSame('acme-shop-keywords-2026-10-02.csv', Csv::fileName('Acme Shop', 'keywords', '2026-10-02'));
        self::assertSame('cafe-muller-rankings-2026-10-02.csv', Csv::fileName('Café Müller!', 'rankings', '2026-10-02'));
        self::assertSame('keywords-2026-10-02.csv', Csv::fileName('???', 'keywords', '2026-10-02'));
        self::assertSame(60 + strlen('-x-d.csv'), strlen(Csv::fileName(str_repeat('a', 100), 'x', 'd')));
    }

    public function testResponseIsADownload(): void
    {
        $f = new Psr17Factory();
        $response = new ResponseFactory($f, $f)->csv([['a']], 'x-2026-10-02.csv');

        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="x-2026-10-02.csv"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame("\u{FEFF}a\r\n", (string) $response->getBody());
    }

    public function testResponseRejectsUnsafeFileNames(): void
    {
        $f = new Psr17Factory();
        $this->expectException(\InvalidArgumentException::class);
        new ResponseFactory($f, $f)->csv([['a']], "evil\".csv\r\nX: y");
    }
}
