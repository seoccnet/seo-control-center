<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;
use SeoControlCenter\Core\Database\Migrations\Schema;

/*
 * AI assistant (ADR-028 amendment): a workspace chooses Claude (anthropic)
 * or GPT (openai). Existing setups are Claude.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        Schema::addColumn($db, 'assistant_settings', 'provider', "VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'anthropic' AFTER workspace_id");
    }
};
