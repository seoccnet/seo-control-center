<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

/**
 * What happens to the link graph when an audit completes (ADR-040, F1).
 */
interface LinkSuggestionScheduler
{
    /** Drops the paragraph text of older audits and queues the suggestions of this one. */
    public function auditCompleted(SiteAudit $audit): void;
}
