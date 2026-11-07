<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities;

use SeoControlCenter\Domain\Opportunities\OpportunityConsolidator;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;

final readonly class OpportunityCatalog
{
    public function __construct(private OpportunityRepository $repository) {}

    /** @return array{groups: array<string, list<StoredOpportunity>>, truncated: bool} */
    public function snapshot(int $projectId): array
    {
        $snapshot = $this->repository->catalog($projectId);
        $truncated = $snapshot['total'] > count($snapshot['rows']);
        $groups = [];
        foreach (StoredOpportunity::STATUSES as $status) {
            $groups[$status] = $truncated && $status === StoredOpportunity::OPEN ? [] : (new OpportunityConsolidator())->consolidate($snapshot['rows'], $status);
        }
        return ['groups' => $groups, 'truncated' => $truncated];
    }

    /** @return array{rows: list<StoredOpportunity>, total: int, truncated: bool} */
    public function list(int $projectId, string $status, ?OpportunityType $type = null, ?\SeoControlCenter\Domain\Opportunities\OpportunityFilter $filter = null): array
    {
        $snapshot = $this->repository->catalog($projectId);
        $truncated = $snapshot['total'] > count($snapshot['rows']);
        // A partial catalog cannot prove that a matching done/dismissed decision is absent.
        $rows = $truncated && $status === StoredOpportunity::OPEN ? [] : (new OpportunityConsolidator())->consolidate($snapshot['rows'], $status, $type);
        if ($filter !== null) {
            $rows = array_values(array_filter($rows, $filter->matches(...)));
        }
        return ['rows' => $rows, 'total' => count($rows), 'truncated' => $truncated];
    }
}
