<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * AI mentions (1.3) are stored like the other research lookups: `llm` holds
 * how often ChatGPT and Google's AI answers mention the project and its
 * competitors, `llm_prompts` the questions whose answers mention the project.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "ALTER TABLE research_results
                MODIFY kind ENUM('ranked', 'gap', 'ideas', 'competitors', 'overview', 'llm', 'llm_prompts') NOT NULL",
        );
    }
};
