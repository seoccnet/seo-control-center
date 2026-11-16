<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SiteHealth\HealthRules;
use SeoControlCenter\Domain\SiteHealth\RegistrableDomain;

/**
 * Site health monitoring (1.3.0) for each project's own site, on by
 * default and free: every 15 minutes the home page is requested (two
 * failed checks in a row count as down); once a day the TLS certificate,
 * robots.txt and the sitemaps are read; once a week the domain's
 * registration expiry is looked up over RDAP. Changes become events, which
 * the alert scan sends to the people who want them.
 *
 * Controllers authorize the project before calling; switching monitoring
 * off and checking now need Edit projects.
 */
final readonly class SiteHealthService
{
    public const int UPTIME_MINUTES = 15;
    public const int DAILY_HOURS = 24;
    public const int DOMAIN_DAYS = 7;
    public const int DISPATCH_LIMIT = 200;
    public const int KEEP_EVENTS = 200;
    public const int SITEMAP_LIMIT = 50000;
    private const int ROBOTS_BYTES = 512 * 1024;
    private const int ROBOTS_KEEP = 65000;
    private const int CHECKS_PER_HOUR = 3;

    public function __construct(
        private SiteHealthRepository $health,
        private SiteHealthScheduler $scheduler,
        private SiteProbe $site,
        private CertificateProbe $certificates,
        private DomainExpiryLookup $domains,
        private Authorizer $auth,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{state: SiteHealthState, events: list<array{id: string, kind: string, title: string, detail: string, created_at: string}>, sslDays: ?int, domainDays: ?int}
     */
    public function overview(Project $project): array
    {
        $state = $this->health->state($project->id);
        $now = $this->clock->now()->getTimestamp();
        $days = static fn(?string $at): ?int => $at === null ? null : (int) floor(((int) strtotime($at . ' UTC') - $now) / 86400);

        return ['state' => $state, 'events' => $this->health->events($project->id, null, 50), 'sslDays' => $days($state->sslExpiresAt), 'domainDays' => $days($state->domainExpiresAt)];
    }

    /** @return list<array{id: string, kind: string, title: string, detail: string, created_at: string}> */
    public function recentEvents(int $projectId, string $since): array
    {
        return $this->health->events($projectId, $since, 50);
    }

    public function canEdit(User $user, Project $project): bool
    {
        return $this->auth->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
    }

    public function setEnabled(User $user, Project $project, bool $enabled, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->health->setEnabled($project->id, $project->workspaceId, $enabled, $this->now());
        $this->audit->record(new AuditEvent($enabled ? 'project.site_health_enabled' : 'project.site_health_disabled', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    public function checkNow(User $user, Project $project): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        if (!$this->health->state($project->id)->enabled) {
            throw new HttpException(422, 'Site health monitoring is off for this project. Turn it on first.');
        }
        if (!$this->rateLimiter->hit('site-health:project:' . $project->id, self::CHECKS_PER_HOUR, 3600)->allowed) {
            throw new HttpException(429, 'Checks were run a few times this hour already. Try again later.');
        }
        $slot = 'now:' . $this->clock->now()->format('YmdHis');
        $this->scheduler->check($project->id, $project->workspaceId, $project->primaryDomain, 'uptime', $slot);
        $this->scheduler->check($project->id, $project->workspaceId, $project->primaryDomain, 'daily', $slot);
    }

    /** Dispatch job: queues the checks that are due. */
    public function dispatch(): int
    {
        $now = $this->clock->now();
        $queued = 0;
        // Slightly early, so a cron run a minute late does not skip a slot.
        foreach ($this->health->due('uptime', $now->modify('-' . (self::UPTIME_MINUTES - 2) . ' minutes')->format('Y-m-d H:i:s'), self::DISPATCH_LIMIT) as $p) {
            $this->scheduler->check($p['projectId'], $p['workspaceId'], $p['domain'], 'uptime', $now->format('YmdH') . ':' . intdiv((int) $now->format('i'), self::UPTIME_MINUTES));
            ++$queued;
        }
        foreach ($this->health->due('daily', $now->modify('-' . (self::DAILY_HOURS - 1) . ' hours')->format('Y-m-d H:i:s'), self::DISPATCH_LIMIT) as $p) {
            $this->scheduler->check($p['projectId'], $p['workspaceId'], $p['domain'], 'daily', $now->format('Ymd'));
            ++$queued;
        }
        $this->health->pruneEvents(self::KEEP_EVENTS);

        return $queued;
    }

    /**
     * Job: one check of a project's site.
     *
     * @param 'uptime'|'daily' $kind
     */
    public function check(int $projectId, int $workspaceId, string $domain, string $kind): void
    {
        $state = $this->health->state($projectId);
        if (!$state->enabled) {
            return;
        }
        $host = self::host($domain);
        if ($host === null) {
            return;
        }
        $state = $kind === 'uptime' ? $this->uptime($state, $host) : $this->daily($state, $host);
        $this->health->save($workspaceId, $state, $this->now());
    }

    private function uptime(SiteHealthState $state, string $host): SiteHealthState
    {
        $now = $this->now();
        $answer = $this->site->get('https://' . $host . '/', 64 * 1024);
        $up = HealthRules::isUp($answer['status']);
        $this->record($state->projectId, HealthRules::uptime($state->failures, $state->downSince, $up, $answer['status'], $answer['error'], $now));
        $failures = $up ? 0 : min(65535, $state->failures + 1);

        return $state->with([
            'uptimeStatus' => $up ? 'up' : ($failures >= HealthRules::DOWN_AFTER ? 'down' : ($state->uptimeStatus ?? 'up')),
            'uptimeCode' => $answer['status'],
            'uptimeMs' => $answer['ms'],
            'uptimeError' => $up ? null : ($answer['error'] ?? ($answer['status'] !== null ? 'HTTP ' . $answer['status'] : 'No answer')),
            'failures' => $failures,
            'downSince' => $up ? null : ($failures === HealthRules::DOWN_AFTER ? $now : $state->downSince),
            'uptimeCheckedAt' => $now,
        ]);
    }

    private function daily(SiteHealthState $state, string $host): SiteHealthState
    {
        $now = $this->now();
        $changes = ['dailyCheckedAt' => $now];

        $certificate = $this->certificates->certificate($host);
        $changes += ['sslExpiresAt' => $certificate['expires'], 'sslIssuer' => $certificate['issuer'], 'sslError' => $certificate['error']];
        $events = HealthRules::expiry(HealthRules::SSL, $certificate['expires'], $certificate['error'], $now, 'the TLS certificate');
        if ($certificate['error'] !== null && $state->sslError === null) {
            $events[] = ['kind' => HealthRules::SSL, 'key' => 'ssl-error:' . substr($now, 0, 10), 'title' => 'The TLS certificate has a problem', 'detail' => $certificate['error']];
        }

        $robots = $this->site->get('https://' . $host . '/robots.txt', self::ROBOTS_BYTES);
        if ($robots['status'] !== null) {
            $text = $robots['status'] === 200 ? $robots['body'] : '';
            $hash = sha1($text);
            $events = [...$events, ...HealthRules::robots($state->robotsStatus, $state->robotsHash, $state->robotsText, $robots['status'], $hash, $text)];
            $changed = $state->robotsHash !== null && ($state->robotsHash !== $hash || $state->robotsStatus !== $robots['status']);
            $changes += ['robotsStatus' => $robots['status'], 'robotsHash' => $hash, 'robotsText' => mb_substr($text, 0, self::ROBOTS_KEEP), 'robotsChangedAt' => $changed ? $now : $state->robotsChangedAt];

            $urls = $this->site->sitemapUrls($host, $text, self::SITEMAP_LIMIT);
            $events = [...$events, ...HealthRules::sitemap($state->sitemapUrls, $urls, substr($now, 0, 10))];
            $changes += ['sitemapUrls' => $urls, 'sitemapChangedAt' => $state->sitemapUrls !== null && $state->sitemapUrls !== $urls ? $now : $state->sitemapChangedAt];
        }

        $domain = RegistrableDomain::of($host);
        if ($domain !== null && ($state->domainCheckedAt === null || $state->domainName !== $domain || $state->domainCheckedAt < $this->clock->now()->modify('-' . self::DOMAIN_DAYS . ' days')->format('Y-m-d H:i:s'))) {
            $lookup = $this->domains->expiry($domain);
            $changes += ['domainName' => $domain, 'domainExpiresAt' => $lookup['expires'], 'domainRegistrar' => $lookup['registrar'], 'domainError' => $lookup['error'], 'domainCheckedAt' => $now];
        }
        $domainExpires = $changes['domainExpiresAt'] ?? $state->domainExpiresAt;
        $events = [...$events, ...HealthRules::expiry(HealthRules::DOMAIN, is_string($domainExpires) ? $domainExpires : null, null, $now, 'the domain ' . ($domain ?? $host))];
        $this->record($state->projectId, $events);

        return $state->with($changes);
    }

    /**
     * @param list<array{kind: string, key: string, title: string, detail: string}> $events
     */
    private function record(int $projectId, array $events): void
    {
        foreach ($events as $event) {
            if ($this->health->addEvent($projectId, $event['kind'], $event['key'], $event['title'], $event['detail'], $this->now())) {
                $this->logger->info('Site health event', ['project_id' => $projectId, 'kind' => $event['kind']]);
            }
        }
    }

    /** The project's host, or null when it is not a plain public host name. */
    private static function host(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $host = str_contains($domain, '://') ? (string) parse_url($domain, PHP_URL_HOST) : (string) preg_replace('#[/?\#].*$#', '', $domain);

        return preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1 ? $host : null;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
