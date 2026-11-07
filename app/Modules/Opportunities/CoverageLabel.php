<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities;

/**
 * How a data-coverage status reads on screen. A search sample larger than
 * the analysed top rows keeps status "missing" for scoring (only a complete
 * sample counts as available), but it is not absent: it reads "partial".
 */
final class CoverageLabel
{
    private function __construct() {}

    /** @param array{status: string, date: ?string, notice?: string} $item */
    public static function status(array $item): string
    {
        if ($item['status'] === 'missing' && str_starts_with($item['notice'] ?? '', 'Analysis covers top ')) {
            return 'partial';
        }

        return $item['status'];
    }
}
