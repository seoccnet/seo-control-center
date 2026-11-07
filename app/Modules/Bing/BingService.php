<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing;

use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Modules\Bing\Api\BingWebmasterClient;

/**
 * Application service for Bing Webmaster Tools: the workspace's API key
 * (integrations.manage), project links and import requests. The key is
 * checked against Bing before it is stored encrypted.
 */
final readonly class BingService
{
    /** Bing's reports trail today by a few days. */
    private const int LAG_DAYS = 3;
    /** Newest data older than this is called out: the site had no impressions since. */
    private const int STALE_DAYS = 14;

    public function __construct(
        private BingAccountRepository $accounts,
        private BingDataRepository $data,
        private BingWebmasterClient $client,
        private BingImportScheduler $imports,
        private Authorizer $authorizer,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private FeatureManager $features,
        private Clock $clock,
    ) {}

    /**
     * The project's Bing figures for the last $days days, or null without imported data.
     *
     * The range ends on the later of the newest imported day and the latest day
     * Bing can have reported (three days ago, Pacific Time). Bing sends only days
     * with impressions, so anchoring on the newest row alone would quietly show
     * an old period for a site that has had none since.
     *
     * @return array{range: DateRange, totals: Metrics, previous: Metrics, hasPrevious: bool, staleSince: ?string}|null
     */
    public function window(Project $project, int $days): ?array
    {
        $span = $this->data->dataSpan($project->id);
        if ($span === null) {
            return null;
        }
        $today = $this->clock->now()->setTimezone(new \DateTimeZone('America/Los_Angeles'))->setTime(0, 0);
        $range = DateRange::endingOn(max($span['last'], $today->modify('-' . self::LAG_DAYS . ' days')->format('Y-m-d')), $days);
        $previous = $range->previous();

        return [
            'range' => $range,
            'totals' => $this->data->totals($project->id, $range),
            'previous' => $this->data->totals($project->id, $previous),
            'hasPrevious' => $span['first'] <= $previous->toString(),
            'staleSince' => $span['last'] < $today->modify('-' . self::STALE_DAYS . ' days')->format('Y-m-d') ? $span['last'] : null,
        ];
    }

    /*
     * Read access for other modules (overview, opportunities, MCP). The caller
     * has already loaded the project (access checked); data only counts while
     * a Bing site is linked.
     */

    public function hasData(int $projectId): bool
    {
        return $this->span($projectId) !== null;
    }

    /**
     * @return array{first: string, last: string}|null oldest and newest imported day, while a site is linked
     */
    public function span(int $projectId): ?array
    {
        return $this->data->link($projectId) !== null ? $this->data->dataSpan($projectId) : null;
    }

    public function totals(int $projectId, DateRange $range): Metrics
    {
        return $this->data->totals($projectId, $range);
    }

    /**
     * @return list<DailyMetrics>
     */
    public function daily(int $projectId, DateRange $range): array
    {
        return $this->data->daily($projectId, $range);
    }

    /**
     * Top queries or pages of the weekly buckets ending inside the range.
     *
     * @return list<DimensionRow>
     */
    public function top(int $projectId, PerformanceDimension $dimension, DateRange $range, int $limit): array
    {
        return $dimension === PerformanceDimension::Query || $dimension === PerformanceDimension::Page ? $this->data->top($projectId, $dimension, $range, $limit) : [];
    }

    /**
     * @return array{account: ?BingAccount, canManage: bool}
     */
    public function overview(User $user, Workspace $workspace): array
    {
        $this->authorizer->authorize($user->subject(), Permission::WorkspaceView, $workspace->id);

        return [
            'account' => $this->accounts->find($workspace->id),
            'canManage' => $this->authorizer->can($user->subject(), Permission::IntegrationsManage, $workspace->id),
        ];
    }

    /**
     * @return list<string> the account's verified sites
     * @throws ValidationException when the key is malformed or Bing rejects it
     * @throws IntegrationException when Bing cannot be reached
     */
    public function connect(User $user, Workspace $workspace, #[SensitiveParameter] string $apiKey, ClientInfo $client): array
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        $apiKey = trim($apiKey);
        if (preg_match('/^[A-Za-z0-9]{16,128}$/D', $apiKey) !== 1) {
            throw new ValidationException(['api_key' => 'Enter the API key from Bing Webmaster Tools (Settings → API Access → API Key).']);
        }
        $this->throttle($user);
        try {
            $sites = $this->client->sites($apiKey);
        } catch (IntegrationException $e) {
            if ($e->authorizationLost) {
                throw new ValidationException(['api_key' => 'Bing rejected this API key.']);
            }
            throw $e;
        }

        $this->accounts->save($workspace->id, $apiKey, $sites, $user->id);
        $this->audit->record(new AuditEvent('integration.bing.connected', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'bing', metadata: [
            'sites' => count($sites),
        ]), $client);

        return $sites;
    }

    /**
     * Reloads the account's site list (also confirms the key still works).
     *
     * @return list<string>
     * @throws IntegrationException
     */
    public function refreshSites(User $user, Workspace $workspace): array
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        $apiKey = $this->accounts->apiKey($workspace->id) ?? throw new HttpException(409, 'Bing is not connected.');
        $this->throttle($user);
        try {
            $sites = $this->client->sites($apiKey);
        } catch (IntegrationException $e) {
            if ($e->authorizationLost) {
                $this->accounts->setError($workspace->id, $e->getMessage());
            }
            throw $e;
        }
        $this->accounts->updateSites($workspace->id, $sites);

        return $sites;
    }

    /**
     * Deletes the API key. Project links and imported data are kept.
     */
    public function disconnect(User $user, Workspace $workspace, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        if ($this->accounts->find($workspace->id) === null) {
            throw new HttpException(409, 'Bing is not connected.');
        }
        $this->accounts->delete($workspace->id);
        $this->audit->record(new AuditEvent('integration.bing.disconnected', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'bing'), $client);
    }

    public function projectLink(Project $project): ?BingSiteLink
    {
        return $this->data->link($project->id);
    }

    /**
     * Sites the project can be linked to (the workspace account's verified sites).
     *
     * @return list<string>
     */
    public function linkableSites(Project $project): array
    {
        return $this->accounts->find($project->workspaceId)->sites ?? [];
    }

    /**
     * @throws ValidationException
     */
    public function linkProject(User $user, Project $project, string $siteUrl, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $project->workspaceId, $project->id);
        if (!in_array($siteUrl, $this->linkableSites($project), true)) {
            throw new ValidationException(['site' => 'Choose one of the verified sites of the connected Bing account.']);
        }
        $this->data->saveLink($project->id, $project->workspaceId, $siteUrl, $user->id);
        if ($this->features->enabled(Feature::BingWebmaster)) {
            $this->imports->requestImport($project->id, $project->workspaceId);
        }
        $this->audit->record(new AuditEvent('project.bing_linked', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'site' => $siteUrl,
        ]), $client);
    }

    public function unlinkProject(User $user, Project $project, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $project->workspaceId, $project->id);
        $this->data->unlink($project->id);
        $this->audit->record(new AuditEvent('project.bing_unlinked', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    /**
     * "Import now" (collapses with requests from the last few minutes).
     */
    public function requestImport(User $user, Project $project): bool
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $project->workspaceId, $project->id);
        $this->features->require(Feature::BingWebmaster);
        if ($this->data->link($project->id) === null) {
            return false;
        }
        $this->imports->requestImport($project->id, $project->workspaceId);

        return true;
    }

    public static function describe(IntegrationException $e): string
    {
        return $e->retryable ? 'Bing Webmaster Tools could not be reached right now. Please try again in a few minutes.' : $e->getMessage();
    }

    private function throttle(User $user): void
    {
        if (!$this->rateLimiter->hit('bing:check:user:' . $user->id, 10, 600)->allowed) {
            throw new HttpException(429, 'Too many Bing checks. Try again in a few minutes.');
        }
    }
}
