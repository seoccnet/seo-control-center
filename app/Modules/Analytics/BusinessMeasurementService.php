<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Analytics\BusinessEvent;
use SeoControlCenter\Domain\Analytics\BusinessMeasurement;
use SeoControlCenter\Domain\Analytics\Ga4Credentials;
use SeoControlCenter\Domain\Analytics\Ga4Provider;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Conversion diagnostics (ADR-042): which GA4 events count as business
 * success, whether GA4 recorded them, and an owner's own test conversion
 * checked read-only against GA4's realtime report. SEOcc never sends,
 * buys or submits anything to produce an event.
 */
final readonly class BusinessMeasurementService
{
    public const string CHECK_JOB = 'ga4.event_check';

    /** @param ?\Closure(int, DateRange): array{clicks: int, covered: int} $searchClicks Search Console Google clicks and covered days */
    public function __construct(
        private BusinessEventRepository $events,
        private Ga4Repository $ga4,
        private Ga4Provider $provider,
        private Ga4Scheduler $scheduler,
        private Authorizer $auth,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private Clock $clock,
        private ?\Closure $searchClicks = null,
    ) {}

    /**
     * The assessment of the last 28 complete GA4 days, GA4 organic sessions
     * next to Search Console clicks, the limitations and the latest checks.
     *
     * @return array<string, mixed>
     */
    public function overview(Project $project, ?DateRange $range = null): array
    {
        $import = $this->events->eventImport($project->id);
        $yesterday = $this->clock->now()->modify('-1 day')->format('Y-m-d');
        // The period ends on the last imported GA4 day, so complete GA4 days are judged (0.32.1).
        $range ??= DateRange::endingOn(min($this->ga4->metadata($project->id)['to'] ?? $import['to'] ?? $yesterday, $yesterday), 28);
        $linked = $this->ga4->propertyId($project->id) !== null;
        $credentials = $this->ga4->hasCredentials($project->workspaceId);
        $sessionDays = $linked ? $this->ga4->coveredDays($project->id, $range) : 0;
        $sessions = $sessionDays === $range->days() ? $this->ga4->totals($project->id, $range)['sessions'] : null;
        $events = $this->events->events($project->id);
        $search = $this->searchClicks !== null ? ($this->searchClicks)($project->id, $range) : null;

        return [
            'assessment' => BusinessMeasurement::assess($events, ['credentials' => $credentials, 'linked' => $linked], $import, $this->events->counts($project->id, $range), $range, $sessions),
            'import' => $import,
            'ga4' => ['linked' => $linked, 'credentials' => $credentials, 'organic_sessions' => $sessions, 'covered_days' => $sessionDays, 'timezone' => $import['timezone'] ?? $this->ga4->metadata($project->id)['timezone'] ?? null],
            // Search Console runs 2-3 days behind GA4: a partial sum is shown with its coverage, never as the full period.
            'search_console' => $search === null ? null : ['clicks' => $search['covered'] > 0 ? $search['clicks'] : null, 'covered_days' => $search['covered'], 'partial' => $search['covered'] < $range->days()],
            'project_timezone' => $project->timezone,
            'limitations' => BusinessMeasurement::LIMITATIONS,
            'checks' => $this->events->checks($project->id),
            'kinds' => BusinessEvent::KINDS,
            'max_events' => BusinessEvent::MAX_PER_PROJECT,
        ];
    }

    public function define(User $user, Project $project, string $kind, string $eventName, string $label, ClientInfo $client): BusinessEvent
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $eventName = trim($eventName);
        $label = trim($label);
        if (!isset(BusinessEvent::KINDS[$kind])) {
            throw new HttpException(422, 'Choose purchase, qualified lead, subscription or other goal.');
        }
        if (!BusinessEvent::validName($eventName)) {
            throw new HttpException(422, 'Use the exact GA4 event name: a letter first, then letters, digits or underscores (at most 40), for example purchase or generate_lead.');
        }
        if (mb_strlen($label) > 120) {
            throw new HttpException(422, 'Keep the description to 120 characters.');
        }
        $existing = $this->events->events($project->id);
        if (count($existing) >= BusinessEvent::MAX_PER_PROJECT) {
            throw new HttpException(422, 'A project can have up to ' . BusinessEvent::MAX_PER_PROJECT . ' business events.');
        }
        foreach ($existing as $event) {
            if ($event->eventName === $eventName) {
                throw new HttpException(422, 'This GA4 event is already a business event of this project.');
            }
        }
        $event = $this->events->add($project->workspaceId, $project->id, $kind, $eventName, $label !== '' ? $label : BusinessEvent::KINDS[$kind][0], $user->id);
        if ($this->ga4->propertyId($project->id) !== null && $this->ga4->hasCredentials($project->workspaceId)) {
            $this->scheduler->import($project->id, $project->workspaceId, $project->publicId);
        }
        $this->audit->record(new AuditEvent('analytics.business_event_added', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['kind' => $kind, 'event_name' => $eventName]), $client);

        return $event;
    }

    public function remove(User $user, Project $project, string $id, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        if (!Uuid::isValid($id) || !$this->events->remove($project->id, $id)) {
            throw new NotFoundHttpException();
        }
        $this->audit->record(new AuditEvent('analytics.business_event_removed', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['event_id' => $id]), $client);
    }

    /** Queues a read-only realtime check after the owner made a test conversion themselves. */
    public function requestCheck(User $user, Project $project, string $note, ClientInfo $client): string
    {
        $this->auth->authorize($user->subject(), Permission::IntegrationsManage, $project->workspaceId, $project->id);
        $note = trim($note);
        if ($this->events->events($project->id) === []) {
            throw new HttpException(409, 'Define a business event first.');
        }
        if ($this->ga4->propertyId($project->id) === null || !$this->ga4->hasCredentials($project->workspaceId)) {
            throw new HttpException(409, 'Link a GA4 property and save credentials first.');
        }
        if (mb_strlen($note) > 500) {
            throw new HttpException(422, 'Keep the note to 500 characters.');
        }
        if (!$this->limiter->hit('ga4-check:project:' . $project->id, 3, 600)->allowed) {
            throw new HttpException(429, 'Three checks were requested in the last ten minutes. Wait a few minutes.');
        }
        $id = $this->events->addCheck($project->workspaceId, $project->id, $note, $user->id);
        $this->scheduler->check($id, $project->workspaceId);
        $this->audit->record(new AuditEvent('analytics.event_check_requested', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);

        return $id;
    }

    /** The job: GA4 realtime counts of the project's business events in the last 30 minutes. */
    public function runCheck(string $checkId): void
    {
        $check = $this->events->check($checkId);
        if ($check === null || $check['status'] !== 'queued') {
            return;
        }
        $credentials = $this->ga4->credentials($check['workspace_id']);
        $property = $this->ga4->propertyId($check['project_id']);
        $names = array_map(static fn(BusinessEvent $e): string => $e->eventName, $this->events->events($check['project_id']));
        if ($credentials === null || $property === null || $names === []) {
            $this->events->finishCheck($checkId, null, 'GA4 is no longer linked or no business event is defined.');

            return;
        }
        try {
            $this->events->finishCheck($checkId, $this->provider->realtime($credentials, $property, $names), null);
        } catch (IntegrationException $e) {
            $this->events->finishCheck($checkId, null, $e->getMessage());
        }
    }

    /**
     * Imports the daily counts of the project's business events with the
     * landing-page import. A failure is recorded as the events' own state
     * and never fails the landing-page import.
     */
    public function importEvents(Project $project, #[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, DateRange $range): void
    {
        $names = array_map(static fn(BusinessEvent $e): string => $e->eventName, $this->events->events($project->id));
        if ($names === []) {
            return;
        }
        try {
            $report = $this->provider->events($credentials, $propertyId, $project->primaryDomain, $range, $names);
        } catch (IntegrationException $e) {
            $this->events->recordEventFailure($project->id, $e->getMessage());

            return;
        }
        if ($this->ga4->propertyId($project->id) === $propertyId) {
            $this->events->saveEventReport($project->id, $range, $report);
        }
    }
}
