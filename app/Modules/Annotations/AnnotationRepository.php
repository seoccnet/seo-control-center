<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;

interface AnnotationRepository
{
    /** @return list<Annotation> */
    public function list(Project $project, ?DateRange $range, ?string $url, int $limit, int $offset): array;
    public function count(Project $project, ?DateRange $range, ?string $url): int;
    public function find(Project $project, string $publicId): ?Annotation;
    /**
     * Creates ($publicId null) or updates a note. With $expectedRevision, an
     * update only applies to that revision.
     *
     * @throws \SeoControlCenter\Core\Http\Exception\HttpException 409 on a revision mismatch
     */
    public function save(Project $project, AnnotationInput $input, int $userId, ?string $publicId, ?int $expectedRevision = null, ?string $via = null): Annotation;
    public function remove(Project $project, string $publicId): void;
}
