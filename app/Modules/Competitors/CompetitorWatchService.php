<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;

/**
 * Competitor content watch (1.6.0): once a day, each competitor's sitemap
 * is read and compared with the previous read, so pages they publish or
 * remove show up early, matched to the project's tracked keywords. Opt-in
 * per project; only sitemaps are requested, no pages and no provider.
 */
final readonly class CompetitorWatchService
{
    public const int URL_LIMIT = 10000;
    public const int DAYS = 30;
    private const int CHANGES = 1000;
    private const int DISPATCH = 500;

    public function __construct(
        private CompetitorWatchRepository $watch,
        private CompetitorRepository $competitors,
        private CompetitorSitemapReader $reader,
        private CompetitorWatchScheduler $scheduler,
        private KeywordRepository $keywords,
        private ProjectService $projects,
        private FeatureManager $features,
        private AuditLogger $audit,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{
     *     enabled: bool, canEdit: bool, featureOn: bool, since: string,
     *     competitors: list<array{competitor: Competitor, state: ?array{checked_on: string, baseline_on: ?string, urls: ?int, capped: bool, error: ?string}, new: int, removed: int}>,
     *     changes: list<array{domain: string, url: string, kind: string, date: string, keywords: list<string>}>
     * }
     */
    public function page(User $user, Project $project, bool $matchedOnly): array
    {
        $since = $this->clock->now()->modify('-' . self::DAYS . ' days')->format('Y-m-d');
        $competitors = $this->competitors->forProject($project->id);
        $domains = [];
        foreach ($competitors as $competitor) {
            $domains[$competitor->id] = $competitor->domain;
        }
        $keywords = $this->keywords->texts($project->id);
        $changes = [];
        $counts = [];
        foreach ($this->watch->changes($project->id, $since, self::CHANGES) as $change) {
            $domain = $domains[$change['competitorId']] ?? null;
            if ($domain === null) {
                continue;
            }
            $counts[$change['competitorId']][$change['kind']] = ($counts[$change['competitorId']][$change['kind']] ?? 0) + 1;
            $matched = self::matches($change['url'], $keywords);
            if ($matchedOnly && $matched === []) {
                continue;
            }
            $changes[] = ['domain' => $domain, 'url' => $change['url'], 'kind' => $change['kind'], 'date' => $change['date'], 'keywords' => $matched];
        }
        $states = $this->watch->states($project->id);

        return [
            'enabled' => $this->watch->enabled($project->id),
            'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project),
            'featureOn' => $this->features->enabled(Feature::Competitors),
            'since' => $since,
            'competitors' => array_map(static fn(Competitor $c): array => ['competitor' => $c, 'state' => $states[$c->id] ?? null, 'new' => $counts[$c->id]['new'] ?? 0, 'removed' => $counts[$c->id]['removed'] ?? 0], $competitors),
            'changes' => $changes,
        ];
    }

    public function setEnabled(User $user, Project $project, bool $enabled, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change this project.');
        }
        $this->features->require(Feature::Competitors);
        $this->watch->setEnabled($project->id, $project->workspaceId, $enabled);
        $this->audit->record(new AuditEvent('competitors.watch_' . ($enabled ? 'enabled' : 'disabled'), ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
        if ($enabled) {
            $today = $this->today();
            foreach ($this->competitors->forProject($project->id) as $competitor) {
                $this->scheduler->check($competitor->id, $project->id, $project->workspaceId, $competitor->domain, $today);
            }
        }
    }

    /** Daily: queues a read of every due competitor. */
    public function dispatch(): int
    {
        if (!$this->features->enabled(Feature::Competitors)) {
            return 0;
        }
        $today = $this->today();
        $due = $this->watch->due($today, self::DISPATCH);
        foreach ($due as $row) {
            $this->scheduler->check($row['competitorId'], $row['projectId'], $row['workspaceId'], $row['domain'], $today);
        }

        return count($due);
    }

    /** Job: reads one competitor's sitemap and records the changes. */
    public function check(int $competitorId, int $projectId, int $workspaceId, string $domain): void
    {
        if (!$this->features->enabled(Feature::Competitors) || !$this->watch->enabled($projectId)) {
            return;
        }
        $competitor = null;
        foreach ($this->competitors->forProject($projectId) as $candidate) {
            if ($candidate->id === $competitorId && $candidate->domain === $domain) {
                $competitor = $candidate;
            }
        }
        $today = $this->today();
        $state = $this->watch->states($projectId)[$competitorId] ?? null;
        if ($competitor === null || ($state !== null && $state['checked_on'] === $today && $state['error'] === null)) {
            return;
        }
        try {
            $read = $this->reader->read($domain, self::URL_LIMIT);
        } catch (\RuntimeException $e) {
            $this->watch->fail($competitorId, $projectId, $today, $e->getMessage());

            return;
        }
        // A read that lost more than half the pages is more likely a sitemap that failed to load
        // than a site that deleted them: nothing is marked removed then.
        $partial = $read['capped'] || ($state !== null && ($state['urls'] ?? 0) > 0 && count($read['urls']) < ($state['urls'] ?? 0) / 2);
        $result = $this->watch->record($competitorId, $projectId, $read['urls'], $today, $partial);
        $this->logger->info('Competitor sitemap read', ['project_id' => $projectId, 'competitor_id' => $competitorId, 'urls' => count($read['urls']), ...$result]);
    }

    /**
     * Tracked keywords whose words all appear in the URL's path.
     *
     * @param list<string> $keywords
     * @return list<string> at most three
     */
    public static function matches(string $url, array $keywords): array
    {
        $path = mb_strtolower(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $tokens = array_flip(preg_split('/[^\p{L}\p{N}]+/u', $path, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($tokens === []) {
            return [];
        }
        $found = [];
        foreach ($keywords as $keyword) {
            $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($keyword), -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn(string $w): bool => mb_strlen($w) >= 3 || ctype_digit($w)));
            if ($words !== [] && array_diff($words, array_keys($tokens)) === []) {
                $found[] = $keyword;
                if (count($found) >= 3) {
                    break;
                }
            }
        }

        return $found;
    }

    private function today(): string
    {
        return $this->clock->now()->format('Y-m-d');
    }
}
