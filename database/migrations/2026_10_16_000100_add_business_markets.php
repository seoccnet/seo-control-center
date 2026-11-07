<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Markets in the business profile (ADR-037): the countries and languages the
 * business sells in, as keyword locale keys ("2840:en"). NULL = not set,
 * which leaves research markets unchecked.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_business_profiles' AND COLUMN_NAME = 'markets'",
        );
        if ($exists === 0) {
            $db->statement('ALTER TABLE project_business_profiles ADD COLUMN markets JSON NULL AFTER exclusions');
        }
    }
};
