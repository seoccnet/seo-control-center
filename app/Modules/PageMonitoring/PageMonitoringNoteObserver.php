<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Annotations\Annotation;
use SeoControlCenter\Modules\Annotations\AnnotationObserver;

/**
 * A change note for an exact URL gets a "before" snapshot and an "after"
 * check of that page (ADR-039, E2).
 */
final readonly class PageMonitoringNoteObserver implements AnnotationObserver
{
    public function __construct(private PageMonitoringService $monitoring) {}

    public function recorded(Project $project, Annotation $annotation): void
    {
        if ($annotation->url !== null && $annotation->url !== '') {
            $this->monitoring->noteRecorded($project, $annotation->url);
        }
    }
}
