<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Article ideas across markets:
 * - article_idea_runs.markets: the markets ("location:language") one run
 *   bought, as a JSON list; market keeps the first for older readers;
 * - article_competitor_rows.market: the market of each bought row.
 * Runs and rows saved before stay NULL and count as the run's market.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $columns = [
            ['article_idea_runs', 'markets', 'ALTER TABLE article_idea_runs ADD COLUMN markets TEXT NULL AFTER market'],
            ['article_competitor_rows', 'market', 'ALTER TABLE article_competitor_rows ADD COLUMN market VARCHAR(64) NULL AFTER project_id'],
        ];
        foreach ($columns as [$table, $column, $sql]) {
            $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
            if ($exists === 0) {
                $db->statement($sql);
            }
        }
    }
};
