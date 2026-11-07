<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ArticleIdeas;

/** Queues article idea work (ADR-041, G1). */
interface ArticleIdeaScheduler
{
    /** The paid lookup of one run. */
    public function queue(int $runId, int $workspaceId): void;

    /** A free rebuild of one project's ideas from its stored lookup; one per project and $window. */
    public function rebuild(int $projectId, int $workspaceId, string $window): void;

    /** A free read of one idea's competitor outlines. */
    public function queueOutline(int $projectId, int $workspaceId, string $key): void;

    /** A free build of one topic plan's link map (G2). */
    public function queuePlanLinks(int $planId, int $workspaceId): void;

    /** The paid backlink check of one topic plan (G2). */
    public function queuePlanBacklinks(int $planId, int $workspaceId): void;
}
