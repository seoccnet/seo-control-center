<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Comparison;

use SeoControlCenter\Core\Branding\Branding;
use SeoControlCenter\Core\Pdf\PdfDocument;
use SeoControlCenter\Core\Pdf\TrueTypeFont;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\ResultsFormat;
use SeoControlCenter\Domain\Projects\Project;

/**
 * The client report of one "Why they outrank you" analysis (ADR-038, D2):
 * an A4 PDF with the project's report branding (logo, accent colour, name
 * and footer from Reports), built in-house like the project report. It
 * holds the verdict, what Google ranks, the actions with their evidence,
 * the page plan, internal links and the subtopic coverage table.
 */
final class ComparisonReportPdf
{
    private const float MARGIN = 40.0;
    private const float CONTENT = PdfDocument::WIDTH - 2 * self::MARGIN;
    private const float BOTTOM = PdfDocument::HEIGHT - 56.0;
    private const string TEXT = '#1f2937';
    private const string MUTED = '#6b7280';
    private const string BORDER = '#e5e7eb';
    private const string SHADE = '#f3f4f6';
    private const string DEFAULT_ACCENT = '#2f5fd0';
    private const array BOTTLENECKS = ['format' => 'Wrong page type', 'content' => 'Content gaps', 'freshness' => 'Out of date', 'technical' => 'Page not readable', 'unclear' => 'Likely links or authority'];
    private const array ROLES = ['money' => 'Main page', 'supporting' => 'Supporting page', 'hub' => 'Hub', 'competing' => 'Competing page'];

    private PdfDocument $pdf;
    private int $page = 0;
    private float $y = 0.0;
    private string $accent = self::DEFAULT_ACCENT;

    private function __construct(private readonly string $fontDirectory) {}

    /** @param ?string $logo image bytes (JPEG or PNG) */
    public static function render(string $fontDirectory, Project $project, Analysis $analysis, Branding $branding, ?string $logo, \DateTimeImmutable $now): string
    {
        return (new self($fontDirectory))->build($project, $analysis, $branding, $logo, $now);
    }

    public static function fileName(Project $project, Analysis $analysis): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($project->name . ' ' . $analysis->label)), '-');

        return 'why-they-outrank-' . ($slug !== '' ? substr($slug, 0, 60) : 'topic') . '-' . substr((string) $analysis->finishedAt, 0, 10) . '.pdf';
    }

    private function build(Project $project, Analysis $a, Branding $branding, ?string $logo, \DateTimeImmutable $now): string
    {
        $this->accent = $branding->accentColor ?? self::DEFAULT_ACCENT;
        $this->pdf = new PdfDocument(sprintf('Why they outrank you: %s', $a->label), $branding->name(), $now);
        $this->pdf->addFont('regular', TrueTypeFont::fromFile($this->fontDirectory . '/IBMPlexSans-Regular.ttf'));
        $this->pdf->addFont('bold', TrueTypeFont::fromFile($this->fontDirectory . '/IBMPlexSans-SemiBold.ttf'));
        $this->newPage();
        $path = static fn(string $url): string => (string) preg_replace('#^https?://(www\.)?#', '', $url);
        $explanation = $a->explanation() ?? [];

        $x = self::MARGIN;
        $image = $logo !== null ? $this->pdf->addImage($logo) : null;
        if ($image !== null) {
            $width = min(120.0, 24.0 * $image['width'] / $image['height']);
            $height = $width * $image['height'] / $image['width'];
            $this->pdf->image($this->page, $image['index'], $x, $this->y + (24 - $height) / 2, $width, $height);
            $x += $width + 8;
        }
        $this->pdf->text($this->page, $x, $this->y + 16, $branding->name(), 'bold', 11, $this->accent);
        $this->y += 34;
        $this->pdf->rect($this->page, self::MARGIN, $this->y, self::CONTENT, 2, $this->accent);
        $this->y += 14;
        $this->paragraph(sprintf('Why competitors outrank %s for “%s”', $project->primaryDomain, $a->label), 'bold', 18, self::TEXT, 24);
        $this->paragraph(sprintf('Main search “%s” · %d searches in the topic · analyzed %s', $a->keyword, count($a->keywords), substr((string) $a->finishedAt, 0, 10)), 'regular', 9.5, self::MUTED);
        $this->y += 6;

        $this->heading('Verdict: ' . (self::BOTTLENECKS[$a->bottleneck() ?? 'unclear'] ?? (string) $a->bottleneck()));
        if (is_string($explanation['verdict'] ?? null)) {
            $this->paragraph($explanation['verdict'], 'regular', 10.5, self::TEXT);
            $this->y += 4;
        }
        foreach (is_array($a->verdict['diagnosis']['reasons'] ?? null) ? $a->verdict['diagnosis']['reasons'] : [] as $reason) {
            $this->paragraph((string) $reason, 'regular', 9, self::MUTED);
        }
        foreach (is_array($explanation['do_not_do'] ?? null) ? $explanation['do_not_do'] : [] as $line) {
            $this->bullet('Avoid', (string) $line);
        }

        $format = is_array($a->verdict['format'] ?? null) ? $a->verdict['format'] : [];
        $this->heading('What Google ranks: ' . ucfirst((string) ($format['verdict'] ?? '')));
        $this->paragraph(is_string($explanation['format'] ?? null) && $explanation['format'] !== '' ? $explanation['format'] : (string) ($format['explanation'] ?? ''), 'regular', 9.5, self::TEXT);
        $this->y += 6;
        $this->table(null, [['Position', 60.0, 'right'], ['Page type', 110.0, 'left'], ['Page', self::CONTENT - 170.0, 'left']], array_map(
            static fn(array $r): array => [(string) $r['position'], ucfirst(ResultsFormat::label($r['type'])), $path($r['url']) . ($r['own'] ? '  (your page)' : '')],
            $a->results,
        ));

        $this->heading('Recommended changes');
        if ($a->actions() === []) {
            $this->paragraph('No change to the page stands out; the competing pages likely win on links or domain authority.', 'regular', 9.5, self::MUTED);
        }
        foreach ($a->actions() as $i => $action) {
            $text = $a->actionText($i);
            $detail = ($text !== null ? $text['what_to_do'] . ($text['impact_rationale'] !== '' ? ' Why: ' . $text['impact_rationale'] : '') : $action['what'])
                . ($action['evidence'] !== [] ? ' Seen on: ' . implode(', ', array_map($path, $action['evidence'])) . '.' : '')
                . ' Effort: ' . $action['effort'] . '.'
                . ($text !== null && $text['outline'] !== [] ? ' Suggested outline: ' . implode(' · ', $text['outline']) . '.' : '');
            $this->bullet(sprintf('%d. %s', $i + 1, $action['title']), $detail);
        }

        $plan = is_array($explanation['page_plan'] ?? null) ? $explanation['page_plan'] : [];
        if ($plan !== []) {
            $this->heading('Page plan');
            $this->table(null, [['Role', 100.0, 'left'], ['Page type', 90.0, 'left'], ['Page', 150.0, 'left'], ['Searches', self::CONTENT - 340.0, 'left']], array_map(
                static fn(array $p): array => [self::ROLES[(string) $p['role']] ?? (string) $p['role'], ucfirst(ResultsFormat::label((string) $p['page_type'])), is_string($p['url'] ?? null) ? $path($p['url']) : 'New page', implode(', ', (array) ($p['keywords'] ?? []))],
                $plan,
            ));
        }

        $links = $a->links();
        $linkPlan = is_array($a->verdict['links'] ?? null) ? $a->verdict['links'] : null;
        if ($linkPlan !== null) {
            $this->heading('Internal links');
            if (is_string($linkPlan['note'] ?? null)) {
                $this->paragraph($linkPlan['note'], 'regular', 9.5, self::MUTED);
            }
            foreach ($links as $l) {
                $this->bullet($path($l['source']) . ' → ' . $path($l['target']), sprintf('Anchors: %s. %s %s', implode(', ', array_map(static fn(string $x): string => '“' . $x . '”', $l['anchors'])), $l['placement'], $l['reason']));
            }
            foreach ((array) ($linkPlan['competing'] ?? []) as $pair) {
                $this->bullet('Competing pages', sprintf('%s and %s compete for the same searches: merge them with a redirect or split the searches clearly.', $path((string) $pair[0]), $path((string) $pair[1])));
            }
        }

        $coverage = is_array($a->verdict['coverage'] ?? null) ? array_slice($a->verdict['coverage'], 0, 15) : [];
        if ($coverage !== []) {
            $this->heading('Subtopics covered by competing pages of the same type');
            $this->table(null, [['Subtopic', self::CONTENT - 170.0, 'left'], ['Competitors', 90.0, 'right'], ['Your page', 80.0, 'left']], array_map(
                static fn(array $c): array => [(string) $c['topic'], (string) count((array) $c['competitors']), $c['own'] ? 'covered' : 'missing'],
                $coverage,
            ));
        }
        $this->y += 6;
        $this->paragraph(sprintf('%d of %d competing pages could be read. Only the structure of competing pages (headings, schema, word count, media) was analyzed; their text was not stored. Estimated gains and priorities are tracked in %s.', (int) ($a->verdict['readable'] ?? 0), (int) ($a->verdict['attempted'] ?? 0), $branding->name()), 'regular', 8, self::MUTED);

        $footer = $branding->reportFooter ?? sprintf('Prepared with %s.', $branding->name());
        $total = $this->pdf->pageCount();
        for ($p = 0; $p < $total; ++$p) {
            $y = PdfDocument::HEIGHT - 34;
            $this->pdf->line($p, self::MARGIN, $y - 12, PdfDocument::WIDTH - self::MARGIN, $y - 12, self::BORDER);
            $label = sprintf('Page %d of %d', $p + 1, $total);
            $labelWidth = $this->pdf->width($label, 'regular', 8);
            $this->pdf->text($p, self::MARGIN, $y, $this->fit($footer, 'regular', 8, self::CONTENT - $labelWidth - 16), 'regular', 8, self::MUTED);
            $this->pdf->text($p, PdfDocument::WIDTH - self::MARGIN - $labelWidth, $y, $label, 'regular', 8, self::MUTED);
        }

        return $this->pdf->output();
    }

    /**
     * @param list<array{0: string, 1: float, 2: string}> $columns title, width, alignment
     * @param array<array<int, string>> $rows
     */
    private function table(?string $title, array $columns, array $rows): void
    {
        $rowHeight = 17.0;
        $header = function () use ($columns, $rowHeight): void {
            $this->pdf->rect($this->page, self::MARGIN, $this->y, self::CONTENT, $rowHeight, self::SHADE);
            $this->cells($columns, array_column($columns, 0), 'bold', self::MUTED);
            $this->y += $rowHeight;
        };
        $this->ensure(min(380.0, $rowHeight * (count($rows) + 1)));
        if ($title !== null) {
            $this->pdf->text($this->page, self::MARGIN, $this->y + 10, $title, 'bold', 10.5, self::TEXT);
            $this->y += 16;
        }
        $header();
        foreach ($rows as $row) {
            if ($this->y + $rowHeight > self::BOTTOM) {
                $this->newPage();
                $header();
            }
            $this->cells($columns, $row, 'regular', self::TEXT);
            $this->y += $rowHeight;
            $this->pdf->line($this->page, self::MARGIN, $this->y, self::MARGIN + self::CONTENT, $this->y, self::BORDER);
        }
        $this->y += 12;
    }

    /**
     * @param list<array{0: string, 1: float, 2: string}> $columns
     * @param array<int, string> $values
     */
    private function cells(array $columns, array $values, string $font, string $color): void
    {
        $x = self::MARGIN;
        foreach ($columns as $i => [, $width, $align]) {
            $text = $this->fit($values[$i] ?? '', $font, 8.5, $width - 12);
            $tx = $align === 'right' ? $x + $width - 6 - $this->pdf->width($text, $font, 8.5) : $x + 6;
            $this->pdf->text($this->page, $tx, $this->y + 11.5, $text, $font, 8.5, $color);
            $x += $width;
        }
    }

    private function bullet(string $title, string $detail): void
    {
        $this->ensure(30);
        $this->pdf->rect($this->page, self::MARGIN + 2, $this->y + 5, 3, 3, $this->accent);
        $indent = self::MARGIN + 12;
        foreach ($this->wrap($title, 'bold', 9.5, self::CONTENT - 12) as $line) {
            $this->ensure(14);
            $this->pdf->text($this->page, $indent, $this->y + 9.5, $line, 'bold', 9.5, self::TEXT);
            $this->y += 13;
        }
        foreach ($this->wrap($detail, 'regular', 8.5, self::CONTENT - 12) as $line) {
            $this->ensure(12);
            $this->pdf->text($this->page, $indent, $this->y + 8.5, $line, 'regular', 8.5, self::MUTED);
            $this->y += 11.5;
        }
        $this->y += 4;
    }

    private function heading(string $title): void
    {
        $this->ensure(90);
        $this->y += 8;
        $this->pdf->rect($this->page, self::MARGIN, $this->y + 1, 3, 14, $this->accent);
        $this->pdf->text($this->page, self::MARGIN + 10, $this->y + 12.5, $this->fit($title, 'bold', 13, self::CONTENT - 10), 'bold', 13, self::TEXT);
        $this->y += 24;
    }

    private function paragraph(string $text, string $font, float $size, string $color, ?float $lineHeight = null): void
    {
        $lineHeight ??= $size * 1.4;
        foreach ($this->wrap($text, $font, $size, self::CONTENT) as $line) {
            $this->ensure($lineHeight);
            $this->pdf->text($this->page, self::MARGIN, $this->y + $size, $line, $font, $size, $color);
            $this->y += $lineHeight;
        }
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, float $size, float $width): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->pdf->width($candidate, $font, $size) <= $width) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            while ($this->pdf->width($word, $font, $size) > $width && mb_strlen($word) > 1) {
                $cut = mb_strlen($word);
                while ($cut > 1 && $this->pdf->width(mb_substr($word, 0, $cut), $font, $size) > $width) {
                    --$cut;
                }
                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function fit(string $text, string $font, float $size, float $width): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($this->pdf->width($text, $font, $size) <= $width) {
            return $text;
        }
        $length = mb_strlen($text);
        while ($length > 0 && $this->pdf->width(mb_substr($text, 0, $length) . '…', $font, $size) > $width) {
            --$length;
        }

        return mb_substr($text, 0, $length) . '…';
    }

    private function ensure(float $height): void
    {
        if ($this->y + $height > self::BOTTOM) {
            $this->newPage();
        }
    }

    private function newPage(): void
    {
        $this->page = $this->pdf->addPage();
        $this->y = self::MARGIN;
    }
}
