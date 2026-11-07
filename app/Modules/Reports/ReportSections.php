<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

use SeoControlCenter\Domain\Shared\ValidationException;

final readonly class ReportSections
{
    public const array LABELS = ['search' => 'Search traffic, top pages and queries', 'rankings' => 'Keyword rankings', 'analytics' => 'GA4 conversions and revenue', 'audit' => 'Site audit health', 'opportunities' => 'Open opportunities'];
    /** @param list<string> $enabled */
    public function __construct(public array $enabled = ['search', 'rankings', 'analytics', 'audit', 'opportunities']) {}
    public function has(string $section): bool
    {
        return in_array($section, $this->enabled, true);
    }
    /** @param mixed $input */
    public static function fromInput(mixed $input): self
    {
        if (!is_array($input) || $input === [] || count($input) > count(self::LABELS)) {
            throw new ValidationException(['sections' => 'Select at least one report section.']);
        }
        foreach ($input as $value) {
            if (!is_string($value) || !isset(self::LABELS[$value])) {
                throw new ValidationException(['sections' => 'Select valid report sections.']);
            }
        }
        return new self(array_values(array_unique($input)));
    }
}
