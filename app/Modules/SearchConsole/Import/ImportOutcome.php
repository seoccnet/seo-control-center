<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Import;

final readonly class ImportOutcome
{
    public function __construct(
        public int $daysImported,
        public int $daysRemaining,
        public bool $skipped = false,
    ) {}

    public static function skipped(): self
    {
        return new self(0, 0, true);
    }
}
