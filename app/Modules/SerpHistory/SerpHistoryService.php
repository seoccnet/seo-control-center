<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SerpHistory;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\SerpHistory\SerpChangeDetector;
use SeoControlCenter\Domain\SerpHistory\SerpEvent;
use SeoControlCenter\Domain\SerpHistory\SerpEventRepository;

/**
 * Results-page change history (ADR-039, E1): after each check day, every
 * keyword's check is compared with its previous check on the same device
 * (at most 14 days older) and the changes are stored once. A daily pass
 * repeats the last days (and, the first time, the last 90) so a missed
 * hook never leaves a gap. Reads stored checks only; nothing is bought.
 */
final readonly class SerpHistoryService
{
    public const string JOB = 'serp_history.detect';
    public const int MAX_GAP_DAYS = 14;
    public const int CATCH_UP_DAYS = 10;
    public const int BACKFILL_DAYS = 90;
    public const int KEEP_DAYS = 400;
    public const int PER_PAGE = 50;

    public function __construct(
        private SerpEventRepository $events,
        private RankingRepository $rankings,
        private CompetitorRepository $competitors,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /** Detects and stores the changes of one check day; returns the number of new events. */
    public function detectDay(int $projectId, int $workspaceId, SerpDevice $device, string $date): int
    {
        $domain = $this->rankings->projectDomain($projectId, $workspaceId);
        if ($domain === null) {
            return 0;
        }
        $competitors = array_map(static fn(Competitor $c): string => $c->domain, $this->competitors->forProject($projectId));
        $added = 0;
        $batch = [];
        foreach ($this->rankings->comparableChecks($projectId, $device, $date, self::MAX_GAP_DAYS) as $row) {
            if ($row['previous'] === null) {
                continue;
            }
            foreach (SerpChangeDetector::compare($row['previous'], $row['current'], $domain, $competitors) as $event) {
                $batch[] = ['keyword_id' => $row['keyword_id'], 'previous_on' => $row['previous']->date, ...$event];
            }
            if (count($batch) >= 200) {
                $added += $this->events->record($projectId, $workspaceId, $device->value, $date, $batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $added += $this->events->record($projectId, $workspaceId, $device->value, $date, $batch);
        }

        return $added;
    }

    /** The daily pass over tracked projects; also prunes events older than 400 days. */
    public function catchUp(): int
    {
        $added = 0;
        $today = $this->clock->now();
        foreach ($this->rankings->trackedProjects() as $p) {
            $device = $this->rankings->settings($p['project_id'])->device;
            $days = $this->events->hasAny($p['project_id']) ? self::CATCH_UP_DAYS : self::BACKFILL_DAYS;
            foreach ($this->rankings->checkDays($p['project_id'], $device, $today->modify('-' . $days . ' days')->format('Y-m-d')) as $date) {
                $added += $this->detectDay($p['project_id'], $p['workspace_id'], $device, $date);
            }
        }
        $this->events->prune($today->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d'));
        if ($added > 0) {
            $this->logger->info('Results-page changes recorded', ['events' => $added]);
        }

        return $added;
    }

    /**
     * The Changes page and MCP: events of the project's tracked device.
     *
     * @param array{kinds?: list<string>, q?: string, subject?: string, from?: string, to?: string} $filters
     * @return array{device: string, events: list<SerpEvent>, total: int, counts: array<string, int>, competitors: array<string, array{new_urls: int, entered: int, left: int}>, since: string}
     */
    public function overview(Project $project, array $filters, int $offset = 0, int $limit = self::PER_PAGE, int $windowDays = 30): array
    {
        $device = $this->rankings->settings($project->id)->device->value;
        $since = $this->clock->now()->modify('-' . $windowDays . ' days')->format('Y-m-d');

        return [
            'device' => $device,
            'events' => $this->events->list($project->id, $device, $filters, $limit, max(0, $offset)),
            'total' => $this->events->count($project->id, $device, $filters),
            'counts' => $this->events->counts($project->id, $device, $since),
            'competitors' => $this->events->competitors($project->id, $device, $since),
            'since' => $since,
        ];
    }
}
