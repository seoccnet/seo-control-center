<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;
use SeoControlCenter\Core\Database\Migrations\Schema;

/*
 * Optional provider workspace for the AI assistant key. A Claude key that can act
 * in several Claude workspaces must name one in each request's
 * `anthropic-workspace-id` header (wrkspc_…). Not a secret; NULL = not needed.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        Schema::addColumn($db, 'assistant_settings', 'provider_workspace', 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER model');
    }
};
