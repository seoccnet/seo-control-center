<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\SiteHealth\CertificateProbe;
use SeoControlCenter\Modules\SiteHealth\DomainExpiryLookup;
use SeoControlCenter\Modules\SiteHealth\SiteHealthRepository;
use SeoControlCenter\Modules\SiteHealth\SiteHealthScheduler;
use SeoControlCenter\Modules\SiteHealth\SiteHealthState;
use SeoControlCenter\Modules\SiteHealth\SiteProbe;

/**
 * Site health in memory: state, events, the queue (records checks) and
 * scripted answers for the site, its certificate and its domain.
 */
final class InMemorySiteHealth implements SiteHealthRepository, SiteHealthScheduler, SiteProbe, CertificateProbe, DomainExpiryLookup
{
    /** @var array<int, SiteHealthState> */
    public array $states = [];
    /** @var array<int, int> project id => workspace id */
    public array $workspaces = [];
    /** @var array<int, array<string, array{id: string, kind: string, title: string, detail: string, created_at: string}>> */
    public array $events = [];
    /** @var list<array{projectId: int, domain: string, kind: string, slot: string}> */
    public array $scheduled = [];
    /** @var list<array{projectId: int, workspaceId: int, domain: string}> projects the dispatcher sees */
    public array $projects = [];
    /** @var array<string, array{status: ?int, body: string, ms: ?int, error: ?string}> URL => answer */
    public array $answers = [];
    public int $sitemapCount = 0;
    /** @var array{expires: ?string, issuer: ?string, error: ?string} */
    public array $certificate = ['expires' => null, 'issuer' => null, 'error' => 'not scripted'];
    /** @var array{expires: ?string, registrar: ?string, error: ?string} */
    public array $domain = ['expires' => null, 'registrar' => null, 'error' => 'not scripted'];
    public int $domainLookups = 0;

    public function __construct(private readonly Clock $clock) {}

    public function state(int $projectId): SiteHealthState
    {
        return $this->states[$projectId] ?? new SiteHealthState($projectId);
    }

    public function save(int $workspaceId, SiteHealthState $state, string $now): void
    {
        $this->states[$state->projectId] = $state;
        $this->workspaces[$state->projectId] = $workspaceId;
    }

    public function setEnabled(int $projectId, int $workspaceId, bool $enabled, string $now): void
    {
        $this->save($workspaceId, $this->state($projectId)->with(['enabled' => $enabled]), $now);
    }

    public function due(string $kind, string $before, int $limit): array
    {
        return array_slice(array_values(array_filter($this->projects, function (array $p) use ($kind, $before): bool {
            $state = $this->state($p['projectId']);
            $at = $kind === 'uptime' ? $state->uptimeCheckedAt : $state->dailyCheckedAt;

            return $state->enabled && ($at === null || $at < $before);
        })), 0, $limit);
    }

    public function addEvent(int $projectId, string $kind, string $key, string $title, string $detail, string $now): bool
    {
        if (isset($this->events[$projectId][$key])) {
            return false;
        }
        $this->events[$projectId][$key] = ['id' => Uuid::v7($this->clock), 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'created_at' => $now];

        return true;
    }

    public function events(int $projectId, ?string $since, int $limit): array
    {
        $events = array_values(array_filter($this->events[$projectId] ?? [], static fn(array $e): bool => $since === null || $e['created_at'] >= $since));

        return array_slice(array_reverse($events), 0, $limit);
    }

    public function pruneEvents(int $keep): void
    {
        foreach ($this->events as $projectId => $events) {
            $this->events[$projectId] = array_slice($events, -$keep, null, true);
        }
    }

    public function check(int $projectId, int $workspaceId, string $domain, string $kind, string $slot): void
    {
        $this->scheduled[] = ['projectId' => $projectId, 'domain' => $domain, 'kind' => $kind, 'slot' => $slot];
    }

    public function get(string $url, int $maxBytes): array
    {
        return $this->answers[$url] ?? ['status' => null, 'body' => '', 'ms' => null, 'error' => 'Connection refused'];
    }

    public function sitemapUrls(string $host, string $robotsText, int $limit): int
    {
        return min($limit, $this->sitemapCount);
    }

    public function certificate(string $host): array
    {
        return $this->certificate;
    }

    public function expiry(string $domain): array
    {
        ++$this->domainLookups;

        return $this->domain;
    }
}
