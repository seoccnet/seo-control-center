<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Unlinked brand mentions (1.6.0): pages that name the brand, from
 * DataForSEO Content Analysis, stored like the other backlink lookups in
 * research_results under the new kind `bl_mentions`.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement("ALTER TABLE research_results MODIFY kind ENUM('ranked', 'gap', 'ideas', 'competitors', 'overview', 'llm', 'llm_prompts', 'bl_profiles', 'bl_domains', 'bl_new', 'bl_lost', 'bl_anchors', 'bl_gap', 'bl_links', 'bl_lost_links', 'rendered_audit', 'llm_answers', 'suggestions', 'serp_strength', 'seed_ideas', 'bl_mentions') NOT NULL");
    }
};
