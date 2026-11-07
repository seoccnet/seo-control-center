<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Outlines read before 0.28.1 include site-template headings (an embedded
 * privacy policy, footer blocks). They are marked as needing a new read,
 * so "Read outlines again" is offered at once instead of after a week.
 * Reading is free; nothing is deleted.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("UPDATE article_idea_outlines SET status = 'failed', error = 'Read before 0.28.1, which skips site-template headings. Read the outlines again.' WHERE status = 'done'");
    }
};
