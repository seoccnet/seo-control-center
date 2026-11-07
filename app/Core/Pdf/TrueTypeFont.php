<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Pdf;

/**
 * A TrueType (glyf-outline) font read from a file: Unicode-to-glyph mapping,
 * advance widths and the metrics a PDF font descriptor needs. subset() keeps
 * the glyph numbering and empties every glyph that is not used, so the PDF
 * can address glyphs by their original IDs (Identity-H).
 */
final class TrueTypeFont
{
    /** @var array<string, array{offset: int, length: int}> */
    private array $tables = [];
    /** @var array<int, int> code point => glyph ID */
    private array $cmap = [];
    /** @var list<int> */
    private array $advances = [];

    public readonly int $unitsPerEm;
    public readonly int $numGlyphs;
    public readonly string $postScriptName;
    /** @var array{0: int, 1: int, 2: int, 3: int} */
    public readonly array $bbox;
    public readonly int $ascent;
    public readonly int $descent;
    public readonly int $capHeight;
    public readonly float $italicAngle;
    public readonly bool $fixedPitch;
    private readonly int $locaFormat;

    private function __construct(private readonly string $data)
    {
        if (strlen($data) < 12 || substr($data, 0, 4) !== "\x00\x01\x00\x00") {
            throw new \InvalidArgumentException('Only TrueType fonts with glyf outlines are supported.');
        }
        $count = $this->u16(4);
        for ($i = 0; $i < $count; ++$i) {
            $record = 12 + 16 * $i;
            $tag = substr($data, $record, 4);
            $offset = $this->u32($record + 8);
            $length = $this->u32($record + 12);
            if ($offset + $length > strlen($data)) {
                throw new \InvalidArgumentException('The font file is truncated.');
            }
            $this->tables[$tag] = ['offset' => $offset, 'length' => $length];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new \InvalidArgumentException('The font has no ' . $required . ' table.');
            }
        }

        $head = $this->tables['head']['offset'];
        $this->unitsPerEm = $this->u16($head + 18);
        $this->bbox = [$this->i16($head + 36), $this->i16($head + 38), $this->i16($head + 40), $this->i16($head + 42)];
        $this->locaFormat = $this->i16($head + 50);
        $hhea = $this->tables['hhea']['offset'];
        $this->numGlyphs = $this->u16($this->tables['maxp']['offset'] + 4);

        $os2 = $this->tables['OS/2']['offset'] ?? null;
        $this->ascent = $os2 !== null ? $this->i16($os2 + 68) : $this->i16($hhea + 4);
        $this->descent = $os2 !== null ? $this->i16($os2 + 70) : $this->i16($hhea + 6);
        $this->capHeight = $os2 !== null && $this->u16($os2) >= 2 ? $this->i16($os2 + 88) : $this->ascent;
        $post = $this->tables['post']['offset'] ?? null;
        $this->italicAngle = $post !== null ? $this->i16($post + 4) + $this->u16($post + 6) / 65536 : 0.0;
        $this->fixedPitch = $post !== null && $this->u32($post + 12) !== 0;

        $metrics = $this->u16($hhea + 34);
        $hmtx = $this->tables['hmtx']['offset'];
        $last = 0;
        for ($gid = 0; $gid < $this->numGlyphs; ++$gid) {
            if ($gid < $metrics) {
                $last = $this->u16($hmtx + 4 * $gid);
            }
            $this->advances[] = $last;
        }
        $this->readCmap();
        $this->postScriptName = $this->readName(6) ?? 'Font';
    }

    public static function fromFile(string $path): self
    {
        $data = @file_get_contents($path);
        if (!is_string($data)) {
            throw new \InvalidArgumentException('Cannot read the font file.');
        }

        return new self($data);
    }

    /** Glyph ID of a Unicode code point; 0 (.notdef) when the font lacks it. */
    public function glyph(int $codePoint): int
    {
        return $this->cmap[$codePoint] ?? 0;
    }

    public function hasGlyph(int $codePoint): bool
    {
        return isset($this->cmap[$codePoint]);
    }

    /** Advance width in font units. */
    public function advance(int $glyph): int
    {
        return $this->advances[$glyph] ?? 0;
    }

    /**
     * The font file with only the given glyphs (and .notdef and composite
     * components) keeping outlines; glyph IDs are unchanged.
     *
     * @param list<int> $glyphs
     */
    public function subset(array $glyphs): string
    {
        $keep = [];
        $queue = [0, ...$glyphs];
        while ($queue !== []) {
            $gid = array_pop($queue);
            if ($gid < 0 || $gid >= $this->numGlyphs || isset($keep[$gid])) {
                continue;
            }
            $keep[$gid] = true;
            foreach ($this->components($gid) as $component) {
                if (!isset($keep[$component])) {
                    $queue[] = $component;
                }
            }
        }

        $glyf = '';
        $loca = '';
        for ($gid = 0; $gid < $this->numGlyphs; ++$gid) {
            $loca .= pack('N', strlen($glyf));
            if (isset($keep[$gid])) {
                $bytes = $this->glyphData($gid);
                $glyf .= $bytes . str_repeat("\x00", (4 - strlen($bytes) % 4) % 4);
            }
        }
        $loca .= pack('N', strlen($glyf));

        $head = $this->table('head');
        $head = substr_replace($head, "\x00\x00\x00\x00", 8, 4); // checkSumAdjustment, set below
        $head = substr_replace($head, "\x00\x01", 50, 2);       // long loca offsets
        $tables = ['head' => $head, 'hhea' => $this->table('hhea'), 'maxp' => $this->table('maxp'), 'hmtx' => $this->table('hmtx'), 'loca' => $loca, 'glyf' => $glyf];
        foreach (['cvt ', 'fpgm', 'prep'] as $hinting) {
            if (isset($this->tables[$hinting])) {
                $tables[$hinting] = $this->table($hinting);
            }
        }
        ksort($tables, SORT_STRING);

        $count = count($tables);
        $power = 1;
        $log = 0;
        while ($power * 2 <= $count) {
            $power *= 2;
            ++$log;
        }
        $font = pack('Nnnnn', 0x00010000, $count, $power * 16, $log, $count * 16 - $power * 16);
        $offset = 12 + 16 * $count;
        $body = '';
        $headOffset = 0;
        foreach ($tables as $tag => $bytes) {
            if ($tag === 'head') {
                $headOffset = $offset;
            }
            $font .= $tag . pack('NNN', self::checksum($bytes), $offset, strlen($bytes));
            $padded = $bytes . str_repeat("\x00", (4 - strlen($bytes) % 4) % 4);
            $body .= $padded;
            $offset += strlen($padded);
        }
        $font .= $body;
        $adjustment = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;

        return substr_replace($font, pack('N', $adjustment), $headOffset + 8, 4);
    }

    /** @return list<int> component glyph IDs of a composite glyph */
    private function components(int $gid): array
    {
        $bytes = $this->glyphData($gid);
        if (strlen($bytes) < 10 || self::word($bytes, 0) < 0x8000) {
            return [];
        }
        $found = [];
        $at = 10;
        do {
            if ($at + 4 > strlen($bytes)) {
                break;
            }
            $flags = self::word($bytes, $at);
            $found[] = self::word($bytes, $at + 2);
            $at += 4 + (($flags & 0x0001) !== 0 ? 4 : 2);
            $at += ($flags & 0x0008) !== 0 ? 2 : (($flags & 0x0040) !== 0 ? 4 : (($flags & 0x0080) !== 0 ? 8 : 0));
        } while (($flags & 0x0020) !== 0);

        return $found;
    }

    private function glyphData(int $gid): string
    {
        $loca = $this->tables['loca']['offset'];
        [$start, $end] = $this->locaFormat === 0
            ? [$this->u16($loca + 2 * $gid) * 2, $this->u16($loca + 2 * $gid + 2) * 2]
            : [$this->u32($loca + 4 * $gid), $this->u32($loca + 4 * $gid + 4)];
        if ($end <= $start || $end > $this->tables['glyf']['length']) {
            return '';
        }

        return substr($this->data, $this->tables['glyf']['offset'] + $start, $end - $start);
    }

    private function readCmap(): void
    {
        $cmap = $this->tables['cmap']['offset'];
        $best = null;
        $bestRank = 0;
        for ($i = 0, $n = $this->u16($cmap + 2); $i < $n; ++$i) {
            $platform = $this->u16($cmap + 4 + 8 * $i);
            $encoding = $this->u16($cmap + 6 + 8 * $i);
            $offset = $cmap + $this->u32($cmap + 8 + 8 * $i);
            $format = $this->u16($offset);
            $rank = match (true) {
                $format === 12 && ($platform === 3 && $encoding === 10 || $platform === 0) => 3,
                $format === 4 && ($platform === 3 && $encoding === 1 || $platform === 0) => 2,
                default => 0,
            };
            if ($rank > $bestRank) {
                [$best, $bestRank] = [$offset, $rank];
            }
        }
        if ($best === null) {
            throw new \InvalidArgumentException('The font has no Unicode character map.');
        }
        if ($bestRank === 3) {
            for ($i = 0, $groups = $this->u32($best + 12); $i < $groups; ++$i) {
                $group = $best + 16 + 12 * $i;
                $first = $this->u32($group);
                $last = min($this->u32($group + 4), $first + 0xFFFF);
                $glyph = $this->u32($group + 8);
                for ($c = $first; $c <= $last; ++$c) {
                    $this->cmap[$c] = $glyph + $c - $first;
                }
            }
        } else {
            $segments = $this->u16($best + 6) / 2;
            $ends = $best + 14;
            $starts = $ends + 2 * $segments + 2;
            $deltas = $starts + 2 * $segments;
            $ranges = $deltas + 2 * $segments;
            for ($s = 0; $s < $segments; ++$s) {
                $end = $this->u16($ends + 2 * $s);
                $start = $this->u16($starts + 2 * $s);
                $delta = $this->i16($deltas + 2 * $s);
                $range = $this->u16($ranges + 2 * $s);
                for ($c = $start; $c <= $end && $c !== 0xFFFF; ++$c) {
                    if ($range === 0) {
                        $glyph = ($c + $delta) & 0xFFFF;
                    } else {
                        $glyph = $this->u16($ranges + 2 * $s + $range + 2 * ($c - $start));
                        $glyph = $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                    }
                    if ($glyph !== 0) {
                        $this->cmap[$c] = $glyph;
                    }
                }
            }
        }
    }

    private function readName(int $id): ?string
    {
        if (!isset($this->tables['name'])) {
            return null;
        }
        $name = $this->tables['name']['offset'];
        $strings = $name + $this->u16($name + 4);
        for ($i = 0, $n = $this->u16($name + 2); $i < $n; ++$i) {
            $record = $name + 6 + 12 * $i;
            if ($this->u16($record + 6) !== $id) {
                continue;
            }
            $raw = substr($this->data, $strings + $this->u16($record + 10), $this->u16($record + 8));
            $text = $this->u16($record) === 3 ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : $raw;
            $clean = (string) preg_replace('/[^A-Za-z0-9-]/', '', $text);
            if ($clean !== '') {
                return $clean;
            }
        }

        return null;
    }

    private function table(string $tag): string
    {
        return substr($this->data, $this->tables[$tag]['offset'], $this->tables[$tag]['length']);
    }

    private static function checksum(string $bytes): int
    {
        $bytes .= str_repeat("\x00", (4 - strlen($bytes) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $bytes) ?: [] as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }

        return $sum;
    }

    private function u16(int $at): int
    {
        return self::word($this->data, $at);
    }

    /** Unsigned 16-bit big-endian value; 0 outside the string. */
    private static function word(string $bytes, int $at): int
    {
        $value = $at >= 0 && $at + 2 <= strlen($bytes) ? unpack('n', $bytes, $at) : false;

        return $value !== false ? $value[1] : 0;
    }

    private function i16(int $at): int
    {
        $v = $this->u16($at);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $at): int
    {
        $value = $at >= 0 && $at + 4 <= strlen($this->data) ? unpack('N', $this->data, $at) : false;

        return $value !== false ? $value[1] : 0;
    }
}
