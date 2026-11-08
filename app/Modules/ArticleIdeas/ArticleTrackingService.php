<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ArticleIdeas;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRepository;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTracking;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTrackingRepository;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Prediction check (ADR-041, G3): articles published from ideas, measured
 * weekly from stored Search Console data, and the site's own time to the
 * top 10 once enough of them got there.
 */
final readonly class ArticleTrackingService
{
    public const int LIMIT = 200;

    /**
     * @param (\Closure(int, DateRange, list<string>): list<array{query: string, url: string, week: string, clicks: int, impressions: int, position: float}>)|null $weekly
     *        weekly Search Console rows per search and page (the Pages read model)
     */
    public function __construct(
        private ArticleTrackingRepository $trackings,
        private ArticleIdeaRepository $ideas,
        private ?\Closure $weekly,
        private Authorizer $auth,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /** @return list<ArticleTracking> */
    public function published(Project $project): array
    {
        return $this->trackings->forProject($project, self::LIMIT);
    }

    public function markPublished(User $user, Project $project, string $ideaKey, string $url, string $date, ClientInfo $client): ArticleTracking
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $idea = $this->ideas->idea($project, $ideaKey) ?? throw new NotFoundHttpException();
        $url = trim($url);
        $errors = [];
        if (!self::onSite($url, $project)) {
            $errors['url'] = 'Enter the full address of the published article on ' . self::bare($project->primaryDomain) . '.';
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        $today = $this->clock->now()->format('Y-m-d');
        if ($day === false || $day->format('Y-m-d') !== trim($date) || trim($date) > $today || trim($date) < '2000-01-01') {
            $errors['date'] = 'Enter the publishing date (YYYY-MM-DD), not in the future.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $tracking = $this->track($project, $idea, $url, trim($date), ArticleTracking::MANUAL, $user->id);
        $this->audit->record(new AuditEvent('article_ideas.published', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['keyword' => $idea->mainKeyword, 'url' => $url]), $client);

        return $tracking;
    }

    /**
     * A content brief saved as published (hook from the briefs module): when
     * its target search is an idea's main search, that article is tracked.
     * An article already tracked keeps its address and date.
     */
    public function fromBrief(Project $project, string $keyword, ?string $url, string $status, ?string $publishedAt): void
    {
        if ($status !== 'published' || $url === null || !self::onSite($url, $project)) {
            return;
        }
        $keyword = Keyword::normalize($keyword);
        foreach ($this->ideas->ideas($project, 200) as $idea) {
            if (Keyword::normalize($idea->mainKeyword) !== $keyword) {
                continue;
            }
            foreach ($this->trackings->forProject($project, self::LIMIT) as $existing) {
                if ($existing->ideaKey === $idea->key) {
                    return;
                }
            }
            $this->track($project, $idea, $url, $publishedAt !== null ? substr($publishedAt, 0, 10) : $this->clock->now()->format('Y-m-d'), ArticleTracking::BRIEF, null);

            return;
        }
    }

    public function stop(User $user, Project $project, string $publicId, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $tracking = $this->trackings->find($project, $publicId) ?? throw new NotFoundHttpException();
        $this->trackings->delete($project, $tracking->id);
        $this->audit->record(new AuditEvent('article_ideas.tracking_stopped', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['keyword' => $tracking->mainKeyword]), $client);
    }

    /**
     * Measures every tracked article from stored Search Console data (job).
     *
     * @param \Closure(): bool $renew extends the job's lease; false stops
     */
    public function checkAll(\Closure $renew): int
    {
        if ($this->weekly === null) {
            return 0;
        }
        $today = $this->clock->now()->format('Y-m-d');
        $checked = 0;
        foreach ($this->trackings->due(self::LIMIT) as $tracking) {
            $range = new DateRange((new \DateTimeImmutable($tracking->publishedOn))->modify('-7 days')->format('Y-m-d'), $today);
            $page = self::pageKey($tracking->url);
            $rows = array_values(array_filter(($this->weekly)($tracking->projectId, $range, $tracking->keywords), static fn(array $r): bool => self::pageKey($r['url']) === $page));
            $this->trackings->saveResult($tracking->id, ArticleTracking::measure($rows, $tracking->mainKeyword, $today), $this->clock->now()->format('Y-m-d H:i:s'));
            ++$checked;
            if (!$renew()) {
                break;
            }
        }

        return $checked;
    }

    /**
     * The project's own months to the top 10 by "kind:level", from articles that got there.
     *
     * @return array<string, array{low: int, high: int, count: int}>
     */
    public function calibration(Project $project): array
    {
        return ArticleTracking::calibration($this->trackings->forProject($project, 500));
    }

    private function track(Project $project, ArticleIdea $idea, string $url, string $publishedOn, string $source, ?int $userId): ArticleTracking
    {
        $p = $idea->prediction;

        return $this->trackings->track($project, $idea, array_values(array_unique([$idea->mainKeyword, ...array_column($idea->keywords, 'keyword')])), [
            'traffic' => $p['traffic'],
            'months' => $p['months'],
            'markets' => $idea->marketKeys(),
        ], $url, $publishedOn, $source, $userId, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    private static function onSite(string $url, Project $project): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || !isset($parts['host'])) {
            return false;
        }
        $host = self::bare($parts['host']);
        $site = self::bare($project->primaryDomain);

        return $host === $site || str_ends_with($host, '.' . $site);
    }

    private static function bare(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower(trim($host)));
    }

    /** An address without scheme, www, case of the host or a trailing slash. */
    private static function pageKey(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return $url;
        }

        return self::bare((string) ($parts['host'] ?? '')) . rtrim((string) ($parts['path'] ?? ''), '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
