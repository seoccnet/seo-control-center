<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

use SeoControlCenter\Domain\Projects\Project;

/**
 * Told when a change note was recorded, so other features can react
 * without the change timeline depending on them (ADR-039, E2).
 */
interface AnnotationObserver
{
    public function recorded(Project $project, Annotation $annotation): void;
}
