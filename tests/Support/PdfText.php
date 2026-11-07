<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support;

/**
 * Reads back what PdfDocument wrote: objects, the text of each page (glyph
 * strings decoded through each font's ToUnicode map) and link targets.
 * Only for PDFs produced by SCC itself.
 */
final class PdfText
{
    /** @var array<int, string> object number => body */
    private array $objects = [];

    public function __construct(public readonly string $pdf)
    {
        preg_match_all('/(\d+) 0 obj\n(.*?)\nendobj\n/s', $pdf, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $this->objects[(int) $m[1]] = $m[2];
        }
    }

    /** @return list<string> one string per page, text runs joined by newlines */
    public function pages(): array
    {
        $fonts = [];
        if (preg_match('/\/Font << (.*?) >>/', $this->objects[$this->resourcesRef()] ?? '', $m) === 1) {
            preg_match_all('/\/(F\d+) (\d+) 0 R/', $m[1], $refs, PREG_SET_ORDER);
            foreach ($refs as [, $name, $ref]) {
                $fonts[$name] = $this->toUnicode((int) $ref);
            }
        }
        $pages = [];
        foreach ($this->objects as $body) {
            if (preg_match('/^<< \/Type \/Page \/Parent .* \/Contents (\d+) 0 R/', $body, $p) !== 1) {
                continue;
            }
            $lines = [];
            preg_match_all('/\/(F\d+) [\d.]+ Tf [\d.-]+ [\d.-]+ Td <([0-9A-F]*)> Tj/', $this->stream((int) $p[1]), $runs, PREG_SET_ORDER);
            foreach ($runs as [, $font, $hex]) {
                $text = '';
                foreach (str_split($hex, 4) as $glyph) {
                    $text .= $fonts[$font][$glyph] ?? '?';
                }
                $lines[] = $text;
            }
            $pages[] = implode("\n", $lines);
        }

        return $pages;
    }

    public function text(): string
    {
        return implode("\n", $this->pages());
    }

    /** @return list<string> */
    public function links(): array
    {
        preg_match_all('/\/S \/URI \/URI \((.*?)\) >>/', $this->pdf, $m);

        return array_map(static fn(string $u): string => strtr($u, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\']), $m[1]);
    }

    public function imageCount(): int
    {
        return (int) preg_match_all('/\/Subtype \/Image /', $this->pdf);
    }

    /** @return list<string> embedded font names */
    public function fontNames(): array
    {
        preg_match_all('/\/Subtype \/Type0 \/BaseFont \/([A-Z]{6}\+[A-Za-z0-9-]+)/', $this->pdf, $m);

        return $m[1];
    }

    private function resourcesRef(): int
    {
        foreach ($this->objects as $body) {
            if (preg_match('/^<< \/Type \/Page .* \/Resources (\d+) 0 R/', $body, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** @return array<string, string> glyph hex => text */
    private function toUnicode(int $fontRef): array
    {
        if (preg_match('/\/ToUnicode (\d+) 0 R/', $this->objects[$fontRef] ?? '', $m) !== 1) {
            return [];
        }
        $cmap = (string) preg_replace('/begincodespacerange.*?endcodespacerange/s', '', $this->stream((int) $m[1]));
        preg_match_all('/<([0-9A-F]{4})> <([0-9A-F]+)>/', $cmap, $pairs, PREG_SET_ORDER);
        $map = [];
        foreach ($pairs as [, $glyph, $utf16]) {
            $map[$glyph] = (string) mb_convert_encoding((string) hex2bin($utf16), 'UTF-8', 'UTF-16BE');
        }

        return $map;
    }

    private function stream(int $ref): string
    {
        $body = $this->objects[$ref] ?? '';
        if (preg_match('/^<<(.*?)>>\nstream\n(.*)\nendstream$/s', $body, $m) !== 1) {
            return '';
        }

        return str_contains($m[1], '/FlateDecode') ? (string) gzuncompress($m[2]) : $m[2];
    }
}
