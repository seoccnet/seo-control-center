<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * A query or page from a filtered list, with the previous period when the
 * list compares periods (zero metrics there mean no stored rows).
 */
final readonly class FilteredRow
{
    public function __construct(
        public string $key,
        public Metrics $current,
        public ?Metrics $previous = null,
    ) {}
}
