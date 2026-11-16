<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Featured snippet and "People also ask" (1.5.0): `snippet_domain` is the
 * site holding the featured snippet on the day of the check, `paa_questions`
 * a JSON list of the questions Google showed (at most eight). Both are NULL
 * when the result page had none, and for checks before 1.5.0.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $columns = [
            'snippet_domain' => 'VARCHAR(253) NULL',
            'paa_questions' => 'TEXT NULL',
        ];
        foreach ($columns as $name => $definition) {
            $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['ranking_snapshots', $name]);
            if ($exists === 0) {
                $db->statement('ALTER TABLE ranking_snapshots ADD COLUMN ' . $name . ' ' . $definition);
            }
        }
    }
};
