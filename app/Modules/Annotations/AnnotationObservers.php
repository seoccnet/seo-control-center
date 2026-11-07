<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

use Closure;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Domain\Projects\Project;

/**
 * The registered annotation observers, built on first use. An observer
 * that fails is logged and never undoes the recorded note.
 */
final class AnnotationObservers implements AnnotationObserver
{
    /** @var list<Closure(): AnnotationObserver> */
    private array $factories = [];

    /** @var list<AnnotationObserver>|null */
    private ?array $observers = null;

    public function __construct(private readonly LoggerInterface $logger) {}

    /** @param Closure(): AnnotationObserver $factory */
    public function add(Closure $factory): void
    {
        $this->factories[] = $factory;
        $this->observers = null;
    }

    public function recorded(Project $project, Annotation $annotation): void
    {
        $this->observers ??= array_map(static fn(Closure $factory): AnnotationObserver => $factory(), $this->factories);
        foreach ($this->observers as $observer) {
            try {
                $observer->recorded($project, $annotation);
            } catch (\Throwable $e) {
                $this->logger->warning('Change note observer failed', ['project_id' => $project->id, 'exception' => $e]);
            }
        }
    }
}
