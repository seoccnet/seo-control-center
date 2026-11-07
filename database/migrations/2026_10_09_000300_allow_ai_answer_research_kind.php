<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * AI answer checks: the user's prompts sent to Gemini and Perplexity
 * (DataForSEO LLM Responses) are stored as research kind llm_answers.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement("ALTER TABLE research_results MODIFY kind ENUM('ranked', 'gap', 'ideas', 'competitors', 'overview', 'llm', 'llm_prompts', 'bl_profiles', 'bl_domains', 'bl_new', 'bl_lost', 'bl_anchors', 'bl_gap', 'bl_links', 'bl_lost_links', 'rendered_audit', 'llm_answers') NOT NULL");
    }
};
