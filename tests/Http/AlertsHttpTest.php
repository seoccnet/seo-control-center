<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Alerts\AlertService;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Modules\Alerts\DigestService;
use SeoControlCenter\Modules\Alerts\NotificationSettings;
use SeoControlCenter\Tests\Support\Fakes\FakeMailer;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAlerts;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Alerts and the weekly digest: notification settings, detection (once per
 * event), recipients by project visibility and settings, the Monday digest
 * with the optional AI summary, and tenant isolation.
 */
final class AlertsHttpTest extends TestCase
{
    private Scenario $s;

    private InMemoryAlerts $alerts;

    private FakeMailer $mailer;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        // Monday 5 October 2026.
        $this->s->container->instance(Clock::class, new FrozenClock(new \DateTimeImmutable('2026-10-05 07:00:00 UTC')));
        $alerts = $this->s->container->get(InMemoryAlerts::class);
        $mailer = $this->s->container->get(FakeMailer::class);
        self::assertInstanceOf(InMemoryAlerts::class, $alerts);
        self::assertInstanceOf(FakeMailer::class, $mailer);
        $this->alerts = $alerts;
        $this->mailer = $mailer;
        $this->mailer->configured = true;
    }

    public function testSettingsPageSavesChoicesAndShowsOnlyVisibleProjects(): void
    {
        $member = $this->s->loggedIn($this->s->member);
        $html = (string) $member->get('/account/notifications')->getBody();
        self::assertStringContainsString('Weekly digest', $html);
        self::assertStringContainsString('Acme Shop', $html);
        self::assertStringNotContainsString('Acme Blog', $html, 'Projects the member cannot see are not listed.');
        self::assertStringNotContainsString('Rival Site', $html);

        $response = $member->post('/account/notifications', [
            'alerts' => '1',
            'types' => [AlertType::RankingDrop->value, AlertType::AuditErrors->value],
            'digest' => '1',
            'projects' => [],
        ]);
        self::assertSame(303, $response->getStatusCode());
        $settings = $this->alerts->settings($this->s->member->id);
        self::assertTrue($settings->alerts);
        self::assertTrue($settings->digest);
        self::assertFalse($settings->digestAi);
        self::assertContains(AlertType::TrafficDrop, $settings->mutedTypes);
        self::assertNotContains(AlertType::RankingDrop, $settings->mutedTypes);
        self::assertSame([$this->s->acmeProject->id], $settings->mutedProjects, 'Unchecked projects send no alerts.');
        self::assertStringContainsString('Your notification settings were saved.', (string) $member->get('/account/notifications')->getBody());
    }

    public function testProjectThresholdsRequireEditPermissionAndRejectInvalidValues(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get('/account/notifications');
        $path = '/account/notifications/projects/' . $this->s->acmeProject->publicId;
        $input = ['traffic_drop' => '55', 'minimum_clicks' => '250', 'ranking_cutoff' => '3', 'lost_domain_rank' => '60'];
        self::assertSame(303, $owner->post($path, $input)->getStatusCode());
        self::assertSame(55, $this->alerts->thresholds($this->s->acmeProject->id)->trafficDrop);
        $this->trafficDrop();
        self::assertSame(0, $this->service()->scan(), 'The stored 40% drop is below the configured 55% sensitivity.');
        self::assertSame(303, $owner->post($path, [...$input, 'traffic_drop' => '101'])->getStatusCode());
        self::assertSame(55, $this->alerts->thresholds($this->s->acmeProject->id)->trafficDrop, 'Invalid input cannot overwrite settings.');
        self::assertSame(404, $owner->post('/account/notifications/projects/' . $this->s->rivalProject->publicId, $input)->getStatusCode());
        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewer->get('/account/notifications');
        self::assertSame(403, $viewer->post($path, $input)->getStatusCode());
        $member = $this->s->loggedIn($this->s->member);
        $member->get('/account/notifications');
        self::assertSame(404, $member->post('/account/notifications/projects/' . $this->s->acmeOther->publicId, $input)->getStatusCode());
    }

    public function testMutesOfOtherWorkspacesAreKept(): void
    {
        $this->alerts->saveSettings($this->s->member->id, new NotificationSettings(mutedProjects: [$this->s->rivalProject->id]));
        $this->s->loggedIn($this->s->member)->post('/account/notifications', ['alerts' => '1', 'projects' => [$this->s->acmeProject->publicId]]);

        self::assertSame([$this->s->rivalProject->id], $this->alerts->settings($this->s->member->id)->mutedProjects);
    }

    public function testPageNeedsSignInAndShowsMailNotice(): void
    {
        self::assertSame(303, $this->s->browser()->get('/account/notifications')->getStatusCode());
        $this->mailer->configured = false;
        self::assertStringContainsString('E-mail is not set up', (string) $this->s->loggedIn($this->s->owner)->get('/account/notifications')->getBody());
    }

    public function testTrafficDropIsRecordedOnceAndMailedToMembersWhoSeeTheProject(): void
    {
        $this->trafficDrop();
        $this->alerts->saveSettings($this->s->admin->id, new NotificationSettings(mutedTypes: [AlertType::TrafficDrop]));
        $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryWebhooks::class)->save(new \SeoControlCenter\Modules\Alerts\AlertWebhook('00000000-0000-4000-8000-000000000001', $this->s->acmeProject->id, $this->s->owner->id, true, 'json', 'https://notify.example.test/hook', null));
        $service = $this->service();

        self::assertSame(1, $service->scan());
        self::assertSame(0, $service->scan(), 'The same event is recorded once.');
        self::assertCount(1, $this->alerts->queuedAlerts);
        self::assertCount(1, $this->alerts->queuedWebhooks, 'The scan queues the shared destination once while preserving individual email mutes.');
        $alert = $this->alerts->find($this->alerts->queuedAlerts[0]);
        self::assertNotNull($alert);
        self::assertSame(AlertType::TrafficDrop, $alert->type);
        self::assertSame('Acme Shop: clicks down 67% in the last 7 days', $alert->title);

        self::assertSame(3, $service->send($alert->id), 'Owner, member and viewer; the admin turned traffic alerts off.');
        $to = array_map(static fn($m): string => $m->to, $this->mailer->sent);
        sort($to);
        self::assertSame(['member@acme.test', 'owner@acme.test', 'viewer@acme.test'], $to);
        $last = $this->mailer->last();
        self::assertNotNull($last);
        self::assertStringContainsString('70 clicks from 2026-09-27 to 2026-10-03, against 210', $last->body);
        self::assertStringContainsString('http://scc.test/projects/' . $this->s->acmeProject->publicId . '/performance', $last->body);
        self::assertNotNull($last->html);
        self::assertStringContainsString('Choose what you receive', $last->html);

        self::assertSame(0, $service->send($alert->id), 'Sent once.');

        $html = (string) $this->s->loggedIn($this->s->member)->get('/account/notifications')->getBody();
        self::assertStringContainsString('clicks down 67%', $html, 'Recent alerts are listed.');
        $rival = (string) $this->s->loggedIn($this->s->rivalOwner)->get('/account/notifications')->getBody();
        self::assertStringNotContainsString('clicks down', $rival, 'Alerts stay in their workspace.');
        $unassigned = (string) $this->s->loggedIn($this->s->unassigned)->get('/account/notifications')->getBody();
        self::assertStringNotContainsString('clicks down', $unassigned, 'Only alerts of visible projects.');
    }

    public function testDigestIsQueuedOnMondaysAndSentOncePerWeek(): void
    {
        $this->trafficDrop();
        $this->alerts->saveSettings($this->s->viewer->id, new NotificationSettings(digest: false));
        $digests = $this->digests();

        self::assertSame(5, $digests->dispatchDue(), 'Every active member who wants it (not the viewer), in both workspaces.');
        self::assertSame('2026-W41', $this->alerts->queuedDigests[0]['week']);

        self::assertTrue($digests->send($this->s->member->id, $this->s->acme->id, '2026-W41'));
        self::assertFalse($digests->send($this->s->member->id, $this->s->acme->id, '2026-W41'), 'Once per week.');
        $mail = $this->mailer->last();
        self::assertNotNull($mail);
        self::assertSame('member@acme.test', $mail->to);
        self::assertStringContainsString('Your week in SEO: Acme', $mail->subject);
        self::assertStringContainsString('== Acme Shop ==', $mail->body);
        self::assertStringNotContainsString('Acme Blog', $mail->body, 'Only projects the member can see.');
        self::assertStringContainsString('/reports/view?frequency=weekly', $mail->body);
        self::assertSame([], $this->alerts->summarized, 'No AI summary without consent.');

        self::assertFalse($digests->send($this->s->unassigned->id, $this->s->acme->id, '2026-W41'), 'No digest without visible projects.');
        self::assertFalse($digests->send($this->s->rivalOwner->id, $this->s->acme->id, '2026-W41'), 'Not for other workspaces.');
    }

    public function testDigestsAreOnlyQueuedOnMondays(): void
    {
        $this->s->container->instance(Clock::class, new FrozenClock(new \DateTimeImmutable('2026-10-06 07:00:00 UTC')));

        self::assertSame(0, $this->digests()->dispatchDue());
        self::assertSame([], $this->alerts->queuedDigests);
    }

    public function testDigestHasAiSummaryWhenTheUserAgreed(): void
    {
        $this->alerts->summary = 'Clicks fell on Acme Shop; fix the open errors first.';
        $this->alerts->saveSettings($this->s->owner->id, new NotificationSettings(digestAi: true));

        self::assertTrue($this->digests()->send($this->s->owner->id, $this->s->acme->id, '2026-W41'));
        self::assertCount(1, $this->alerts->summarized);
        self::assertStringContainsString('"name":"Acme Shop"', $this->alerts->summarized[0]);
        self::assertStringNotContainsString('http://', $this->alerts->summarized[0], 'No links in the facts.');
        $mail = $this->mailer->last();
        self::assertNotNull($mail);
        self::assertStringContainsString('Clicks fell on Acme Shop', $mail->body);
        self::assertStringContainsString('AI summary', (string) $mail->html);
    }

    private function trafficDrop(): void
    {
        $performance = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemorySearchPerformance::class, $performance);
        $days = [];
        for ($d = new \DateTimeImmutable('2026-09-20'); $d <= new \DateTimeImmutable('2026-10-03'); $d = $d->modify('+1 day')) {
            $days[] = new DailyMetrics($d->format('Y-m-d'), new Metrics($d < new \DateTimeImmutable('2026-09-27') ? 30 : 10, 1000, 8.0));
        }
        $performance->upsertDailyTotals($this->s->acmeProject->id, $days);
    }

    private function service(): AlertService
    {
        $service = $this->s->container->get(AlertService::class);
        self::assertInstanceOf(AlertService::class, $service);

        return $service;
    }

    private function digests(): DigestService
    {
        $service = $this->s->container->make(DigestService::class);
        self::assertInstanceOf(DigestService::class, $service);

        return $service;
    }
}
