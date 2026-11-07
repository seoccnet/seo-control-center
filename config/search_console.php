<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

return [
    // Google keeps about 16 months of Search Console data.
    'backfill_days' => $env->int('SEARCH_CONSOLE_BACKFILL_DAYS', 486),
    // Rows stored per day for queries and for pages. Google returns the top rows
    // by clicks; more rows mean more long-tail data and more database space.
    'max_rows_per_day' => $env->int('SEARCH_CONSOLE_MAX_ROWS_PER_DAY', 5000),
    // How often the daily totals (and with them newly finalized days) are re-read.
    'totals_refresh_hours' => $env->int('SEARCH_CONSOLE_TOTALS_REFRESH_HOURS', 6),
];
