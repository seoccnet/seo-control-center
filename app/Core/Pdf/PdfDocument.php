<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Pdf;

/**
 * A minimal PDF 1.7 writer for generated reports: A4 pages, text in embedded
 * TrueType fonts (Type0/Identity-H, subset, with a ToUnicode map so text can
 * be searched and copied), filled rectangles, lines, web links and images
 * (JPEG; PNG without transparency). Coordinates are points from the top-left
 * corner. No external library or PHP extension is required; streams are
 * compressed when zlib is available.
 */
final class PdfDocument
{
    public const float WIDTH = 595.28;
    public const float HEIGHT = 841.89;

    /** @var array<int, string> content stream per page */
    private array $pages = [];
    /** @var array<int, list<array{0: float, 1: float, 2: float, 3: float, 4: string}>> */
    private array $links = [];
    /** @var array<string, array{font: TrueTypeFont, used: array<int, string>}> */
    private array $fonts = [];
    /** @var list<array{width: int, height: int, colorSpace: string, bits: int, filter: string, params: ?string, data: string}> */
    private array $images = [];

    public function __construct(
        private readonly string $title,
        private readonly string $creator,
        private readonly \DateTimeImmutable $created,
    ) {}

    public function addFont(string $key, TrueTypeFont $font): void
    {
        $this->fonts[$key] = ['font' => $font, 'used' => []];
    }

    /** Adds a page and returns its index. */
    public function addPage(): int
    {
        $this->pages[] = '';
        $this->links[] = [];

        return count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Width of a single line of text in points. */
    public function width(string $text, string $font, float $size): float
    {
        $ttf = $this->font($font);
        $units = 0;
        foreach (self::codePoints($text) as $codePoint) {
            $units += $ttf->advance($ttf->glyph($codePoint));
        }

        return $units * $size / $ttf->unitsPerEm;
    }

    /** Draws one line of text; $y is the baseline. */
    public function text(int $page, float $x, float $y, string $text, string $font, float $size, string $color = '#000000'): void
    {
        $ttf = $this->font($font);
        $hex = '';
        foreach (self::codePoints($text) as $codePoint) {
            $glyph = $ttf->glyph($codePoint);
            $hex .= sprintf('%04X', $glyph);
            if ($glyph !== 0) {
                $this->fonts[$font]['used'][$glyph] ??= mb_chr($codePoint, 'UTF-8');
            }
        }
        if ($hex === '') {
            return;
        }
        $this->pages[$page] .= sprintf("BT %s /F%d %s Tf %s %s Td <%s> Tj ET\n", self::color($color, 'rg'), $this->fontIndex($font), self::n($size), self::n($x), self::n(self::HEIGHT - $y), $hex);
    }

    public function rect(int $page, float $x, float $y, float $width, float $height, string $fill): void
    {
        $this->pages[$page] .= sprintf("%s %s %s %s %s re f\n", self::color($fill, 'rg'), self::n($x), self::n(self::HEIGHT - $y - $height), self::n($width), self::n($height));
    }

    public function line(int $page, float $x1, float $y1, float $x2, float $y2, string $color, float $width = 0.5): void
    {
        $this->pages[$page] .= sprintf("%s %s w %s %s m %s %s l S\n", self::color($color, 'RG'), self::n($width), self::n($x1), self::n(self::HEIGHT - $y1), self::n($x2), self::n(self::HEIGHT - $y2));
    }

    /** A clickable area that opens an http(s) URL. Other schemes are ignored. */
    public function link(int $page, float $x, float $y, float $width, float $height, string $url): void
    {
        if (preg_match('#^https?://[^\s]+$#iD', $url) === 1) {
            $this->links[$page][] = [$x, self::HEIGHT - $y - $height, $x + $width, self::HEIGHT - $y, $url];
        }
    }

    /**
     * Registers an image (JPEG, or PNG with 8-bit grey/RGB and no
     * transparency or interlacing) and returns its index, or null when the
     * format is not supported.
     *
     * @return array{index: int, width: int, height: int}|null
     */
    public function addImage(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $channels = $info['channels'] ?? 3;
            if (!in_array($channels, [1, 3], true)) {
                return null;
            }
            $this->images[] = ['width' => $info[0], 'height' => $info[1], 'colorSpace' => $channels === 1 ? 'DeviceGray' : 'DeviceRGB', 'bits' => 8, 'filter' => 'DCTDecode', 'params' => null, 'data' => $bytes];
        } elseif ($info[2] === IMAGETYPE_PNG) {
            $png = self::png($bytes);
            if ($png === null) {
                return null;
            }
            $this->images[] = $png;
        } else {
            return null;
        }

        return ['index' => count($this->images) - 1, 'width' => $info[0], 'height' => $info[1]];
    }

    public function image(int $page, int $image, float $x, float $y, float $width, float $height): void
    {
        $this->pages[$page] .= sprintf("q %s 0 0 %s %s %s cm /Im%d Do Q\n", self::n($width), self::n($height), self::n($x), self::n(self::HEIGHT - $y - $height), $image);
    }

    public function output(): string
    {
        if ($this->pages === []) {
            $this->addPage();
        }
        $objects = [];
        $reserve = static function () use (&$objects): int {
            $objects[] = '';

            return count($objects);
        };
        $catalog = $reserve();
        $pagesRoot = $reserve();

        $fontRefs = [];
        foreach (array_keys($this->fonts) as $key) {
            $fontRefs[$this->fontIndex($key)] = $this->writeFont($key, $objects);
        }
        $imageRefs = [];
        foreach ($this->images as $i => $image) {
            $dict = sprintf('/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent %d /Filter /%s', $image['width'], $image['height'], $image['colorSpace'], $image['bits'], $image['filter']);
            if ($image['params'] !== null) {
                $dict .= ' /DecodeParms ' . $image['params'];
            }
            $objects[] = '<< ' . $dict . ' /Length ' . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream";
            $imageRefs[$i] = count($objects);
        }
        $resources = '<< /ProcSet [/PDF /Text /ImageC /ImageB] /Font << ' . implode(' ', array_map(static fn(int $i, int $ref): string => sprintf('/F%d %d 0 R', $i, $ref), array_keys($fontRefs), $fontRefs)) . ' >>'
            . ($imageRefs !== [] ? ' /XObject << ' . implode(' ', array_map(static fn(int $i, int $ref): string => sprintf('/Im%d %d 0 R', $i, $ref), array_keys($imageRefs), $imageRefs)) . ' >>' : '') . ' >>';
        $objects[] = $resources;
        $resourcesRef = count($objects);

        $kids = [];
        foreach ($this->pages as $i => $content) {
            $objects[] = self::stream($content);
            $contentRef = count($objects);
            $annots = [];
            foreach ($this->links[$i] as [$x1, $y1, $x2, $y2, $url]) {
                $objects[] = sprintf('<< /Type /Annot /Subtype /Link /Rect [%s %s %s %s] /Border [0 0 0] /A << /S /URI /URI %s >> >>', self::n($x1), self::n($y1), self::n($x2), self::n($y2), self::literal($url));
                $annots[] = count($objects) . ' 0 R';
            }
            $objects[] = sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources %d 0 R /Contents %d 0 R%s >>', $pagesRoot, self::n(self::WIDTH), self::n(self::HEIGHT), $resourcesRef, $contentRef, $annots !== [] ? ' /Annots [' . implode(' ', $annots) . ']' : '');
            $kids[] = count($objects) . ' 0 R';
        }
        $objects[$pagesRoot - 1] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));
        $objects[$catalog - 1] = sprintf('<< /Type /Catalog /Pages %d 0 R >>', $pagesRoot);
        $date = 'D:' . $this->created->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis') . 'Z';
        $objects[] = sprintf('<< /Title %s /Creator %s /Producer %s /CreationDate %s >>', self::literal($this->title), self::literal($this->creator), self::literal($this->creator), self::literal($date));
        $info = count($objects);

        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $id = md5($pdf);

        return $pdf . sprintf("trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R /ID [<%s> <%s>] >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $catalog, $info, $id, $id, $xref);
    }

    /**
     * @param list<string> $objects
     * @return int reference of the Type0 font
     */
    private function writeFont(string $key, array &$objects): int
    {
        $ttf = $this->fonts[$key]['font'];
        $used = $this->fonts[$key]['used'];
        ksort($used);
        $scale = 1000 / $ttf->unitsPerEm;
        $tag = '';
        foreach (str_split(substr(strtoupper(hash('sha256', $key . implode(',', array_keys($used)))), 0, 6)) as $c) {
            $tag .= chr(65 + hexdec($c) % 26);
        }
        $name = $tag . '+' . $ttf->postScriptName;

        $file = $ttf->subset(array_keys($used));
        $objects[] = self::stream($file, '/Length1 ' . strlen($file));
        $fileRef = count($objects);
        $flags = 32 | ($ttf->fixedPitch ? 1 : 0) | ($ttf->italicAngle !== 0.0 ? 64 : 0);
        $objects[] = sprintf(
            '<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%d %d %d %d] /ItalicAngle %s /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
            $name,
            $flags,
            (int) round($ttf->bbox[0] * $scale),
            (int) round($ttf->bbox[1] * $scale),
            (int) round($ttf->bbox[2] * $scale),
            (int) round($ttf->bbox[3] * $scale),
            self::n($ttf->italicAngle),
            (int) round($ttf->ascent * $scale),
            (int) round($ttf->descent * $scale),
            (int) round($ttf->capHeight * $scale),
            $fileRef,
        );
        $descriptor = count($objects);

        $widths = '';
        foreach ($used as $glyph => $char) {
            $widths .= sprintf('%d [%d] ', $glyph, (int) round($ttf->advance($glyph) * $scale));
        }
        $objects[] = sprintf('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW %d /W [%s] /CIDToGIDMap /Identity >>', $name, $descriptor, (int) round($ttf->advance(0) * $scale), trim($widths));
        $cid = count($objects);

        $map = '';
        foreach (array_chunk($used, 100, true) as $chunk) {
            $map .= count($chunk) . " beginbfchar\n";
            foreach ($chunk as $glyph => $char) {
                $map .= sprintf("<%04X> <%s>\n", $glyph, strtoupper(bin2hex((string) mb_convert_encoding($char, 'UTF-16BE', 'UTF-8'))));
            }
            $map .= "endbfchar\n";
        }
        $objects[] = self::stream("/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" . $map . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend");
        $toUnicode = count($objects);

        $objects[] = sprintf('<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>', $name, $cid, $toUnicode);

        return count($objects);
    }

    private function font(string $key): TrueTypeFont
    {
        return ($this->fonts[$key] ?? throw new \InvalidArgumentException('Unknown font ' . $key))['font'];
    }

    private function fontIndex(string $key): int
    {
        return (int) array_search($key, array_keys($this->fonts), true) + 1;
    }

    private static function stream(string $data, string $extra = ''): string
    {
        $filter = '';
        if (function_exists('gzcompress')) {
            $compressed = gzcompress($data, 6);
            if (is_string($compressed)) {
                [$data, $filter] = [$compressed, ' /Filter /FlateDecode'];
            }
        }

        return '<< /Length ' . strlen($data) . $filter . ($extra !== '' ? ' ' . $extra : '') . " >>\nstream\n" . $data . "\nendstream";
    }

    /**
     * PNG IDAT data passed through with the PNG predictor (no decompression
     * needed): 8-bit greyscale or RGB, not interlaced, no transparency.
     *
     * @return array{width: int, height: int, colorSpace: string, bits: int, filter: string, params: ?string, data: string}|null
     */
    private static function png(string $bytes): ?array
    {
        if (strlen($bytes) < 33 || substr($bytes, 12, 4) !== 'IHDR') {
            return null;
        }
        $header = unpack('Nwidth/Nheight/Cbits/Ctype/Ccompression/Cfilter/Cinterlace', $bytes, 16);
        if ($header === false || $header['bits'] !== 8 || !in_array($header['type'], [0, 2], true) || $header['interlace'] !== 0) {
            return null;
        }
        $data = '';
        for ($at = 8; $at + 8 <= strlen($bytes);) {
            $length = unpack('N', $bytes, $at);
            if ($length === false) {
                return null;
            }
            $length = $length[1];
            $type = substr($bytes, $at + 4, 4);
            if ($type === 'tRNS') {
                return null;
            }
            if ($type === 'IDAT') {
                $data .= substr($bytes, $at + 8, $length);
            }
            if ($type === 'IEND') {
                break;
            }
            $at += 12 + $length;
        }
        if ($data === '') {
            return null;
        }
        $colors = $header['type'] === 2 ? 3 : 1;

        return ['width' => $header['width'], 'height' => $header['height'], 'colorSpace' => $colors === 3 ? 'DeviceRGB' : 'DeviceGray', 'bits' => 8, 'filter' => 'FlateDecode', 'params' => sprintf('<< /Predictor 15 /Colors %d /BitsPerComponent 8 /Columns %d >>', $colors, $header['width']), 'data' => $data];
    }

    /** @return list<int> */
    private static function codePoints(string $text): array
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', mb_scrub($text, 'UTF-8'));

        return array_map(static fn(string $c): int => mb_ord($c, 'UTF-8'), mb_str_split($text, 1, 'UTF-8'));
    }

    private static function color(string $hex, string $operator): string
    {
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/iD', $hex, $m) !== 1) {
            $m = [0, '00', '00', '00'];
        }

        return sprintf('%s %s %s %s', self::n(hexdec($m[1]) / 255), self::n(hexdec($m[2]) / 255), self::n(hexdec($m[3]) / 255), $operator);
    }

    private static function literal(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*$/D', $text) === 1) {
            return '(' . strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        }

        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding(mb_scrub($text, 'UTF-8'), 'UTF-16BE', 'UTF-8'))) . '>';
    }

    private static function n(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }
}
