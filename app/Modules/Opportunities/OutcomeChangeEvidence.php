<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities;

use SeoControlCenter\Domain\Opportunities\ChangeEvidence;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Annotations\Annotation;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\PageMonitoring\PageChangeReader;

/**
 * Change evidence for outcomes from page monitoring and the change timeline.
 */
final readonly class OutcomeChangeEvidence implements ChangeEvidence
{
    public function __construct(private PageChangeReader $pages, private AnnotationRepository $annotations, private ProjectRepository $projects) {}

    public function pageChanges(int $projectId, string $url, string $from, string $to): array
    {
        return $this->pages->changesFor($projectId, $url, $from, $to);
    }

    public function notes(int $projectId, int $workspaceId, ?string $url, string $from, string $to): array
    {
        $project = array_values(array_filter($this->projects->allInWorkspace($workspaceId), static fn(Project $p): bool => $p->id === $projectId))[0] ?? null;
        if ($project === null || $from > $to) {
            return [];
        }

        return array_map(static fn(Annotation $a): array => ['id' => $a->publicId, 'date' => $a->date, 'title' => $a->title, 'url' => $a->url], $this->annotations->list($project, new DateRange($from, $to), $url, 100, 0));
    }
}
