<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Reports\ReportService;
use SeoControlCenter\Tests\Support\Fakes\FakeMailer;
use SeoControlCenter\Tests\Support\Fakes\InMemoryReports;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Scheduled reports: schedule, permissions, the printable report, due
 * dispatch, the plain-text e-mail and tenant isolation.
 */
final class ReportsHttpTest extends TestCase
{
    private Scenario $s;

    private InMemoryReports $reports;

    private FakeMailer $mailer;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        // Friday 2 October 2026: the latest complete week is 21–27 September.
        $this->s->container->instance(Clock::class, new FrozenClock(new \DateTimeImmutable('2026-10-02 12:00:00 UTC')));
        $reports = $this->s->container->get(InMemoryReports::class);
        $mailer = $this->s->container->get(FakeMailer::class);
        $performance = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemoryReports::class, $reports);
        self::assertInstanceOf(FakeMailer::class, $mailer);
        self::assertInstanceOf(InMemorySearchPerformance::class, $performance);
        $this->reports = $reports;
        $this->mailer = $mailer;
        $project = $this->s->acmeProject->id;
        $performance->upsertDailyTotals($project, [
            new DailyMetrics('2026-09-16', new Metrics(40, 1000, 9.0)),
            new DailyMetrics('2026-09-24', new Metrics(50, 1200, 8.0)),
        ]);
        $performance->replaceDay($project, '2026-09-24', ['query' => [new DimensionRow('=red shoes', new Metrics(20, 300, 3.0))], 'page' => [new DimensionRow('https://shop.acme.test/shoes', new Metrics(30, 500, 4.0))]]);
    }

    public function testScheduleIsValidatedAndOnlyEditorsChangeIt(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $invalid = $owner->post($this->url(), ['frequency' => 'daily', 'recipients' => 'not-an-address', 'enabled' => '1']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Choose weekly or monthly.', (string) $invalid->getBody());
        self::assertStringContainsString('&quot;not-an-address&quot; is not a valid e-mail address.', (string) $invalid->getBody());

        self::assertSame(303, $owner->post($this->url(), ['frequency' => 'monthly', 'recipients' => "Client@Shop.test, team@acme.test\nclient@shop.test", 'enabled' => '1'])->getStatusCode());
        $schedule = $this->reports->find($this->s->acmeProject->id);
        self::assertNotNull($schedule);
        self::assertSame(['client@shop.test', 'team@acme.test'], $schedule->recipients, 'Lower-cased and de-duplicated.');
        self::assertSame('monthly', $schedule->frequency);
        self::assertContains('project.report_schedule_saved', $this->s->audit()->actions());

        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringContainsString('Last week', (string) $viewer->get($this->url())->getBody(), 'Viewers see reports.');
        self::assertSame(403, $viewer->post($this->url(), ['frequency' => 'weekly', 'recipients' => 'x@y.test', 'enabled' => '1'])->getStatusCode());
    }

    public function testWorkspaceBrandingAndSectionChoicesApplyToEveryReportFormat(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/branding', ['product_name' => 'Acme Client SEO', 'accent_color' => '#1255aa', 'report_footer' => 'Prepared for the Acme client'])->getStatusCode());
        self::assertSame(303, $owner->post($this->url() . '/sections', ['sections' => ['rankings']])->getStatusCode());
        $html = (string) $owner->get($this->url() . '/view')->getBody();
        self::assertStringContainsString('Acme Client SEO', $html);
        self::assertStringContainsString('<h2>Rankings</h2>', $html);
        self::assertStringNotContainsString('<h2>Opportunities</h2>', $html);
        self::assertStringNotContainsString('stat-value', $html);
        self::assertStringContainsString('Prepared for the Acme client', $html);
        $service = $this->s->container->make(ReportService::class);
        $this->mailer->configured = true;
        $service->send($this->s->acme->id, $this->s->acmeProject->publicId, 'weekly', '2026-09-27', ['client@acme.test'], 'test');
        $message = $this->mailer->sent[0];
        self::assertStringNotContainsString('SEARCH', $message->body);
        self::assertStringNotContainsString('OPPORTUNITIES:', $message->body);
        self::assertStringContainsString('Full report', $message->body);
        self::assertStringContainsString('Acme Client SEO', (string) $message->html);
        self::assertStringNotContainsString('Opportunities <span', (string) $message->html);
        self::assertStringContainsString('#1255aa', (string) $message->html);
        $member = $this->s->loggedIn($this->s->member);
        $member->get($this->url());
        self::assertSame(403, $member->post($this->url() . '/branding', ['product_name' => 'Unauthorized'])->getStatusCode());
        $owner->get($this->url());
        self::assertSame(404, $owner->post('/projects/' . $this->s->rivalProject->publicId . '/reports/branding', ['product_name' => 'Foreign'])->getStatusCode());
        $owner->get($this->url());
        self::assertSame(422, $owner->post($this->url() . '/sections', ['sections' => []])->getStatusCode());
        self::assertSame(['rankings'], $this->reports->sections($this->s->acmeProject->id)->enabled);
    }

    public function testPrintableReportShowsThePeriod(): void
    {
        $html = (string) $this->s->loggedIn($this->s->viewer)->get($this->url() . '/view?frequency=weekly')->getBody();

        self::assertStringContainsString('Acme Shop: Week 39 · 21–27 September 2026', $html);
        self::assertStringContainsString('compared with 2026-09-14 – 2026-09-20', $html);
        self::assertMatchesRegularExpression('#<p class="stat-value">50</p>#', $html, 'Clicks of the week.');
        self::assertStringContainsString('▲ +25.0 %', $html, '50 against 40 the week before.');
        self::assertStringContainsString('/shoes', $html);
        self::assertStringContainsString('data-print', $html);
        self::assertStringContainsString('/assets/js/print.js', $html);

        $future = (string) $this->s->loggedIn($this->s->viewer)->get($this->url() . '/view?frequency=weekly&to=2026-10-11')->getBody();
        self::assertStringContainsString('Week 39', $future, 'Periods that have not ended fall back to the latest complete one.');
    }

    public function testWorkspaceLogoValidationAndScopedDelivery(): void
    {
        $service = $this->s->container->make(\SeoControlCenter\Modules\Reports\ReportCustomization::class);
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 32, 32, 8, 0, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress(str_repeat("\0" . str_repeat("\x80", 32), 32))) . $chunk('IEND', '');
        $service->saveLogo($this->s->owner, $this->s->acmeProject, $png, \SeoControlCenter\Core\Support\ClientInfo::cli());
        $owner = $this->s->loggedIn($this->s->owner);
        $response = $owner->get($this->url() . '/logo');
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame($png, (string) $response->getBody());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(404, $owner->get('/projects/' . $this->s->rivalProject->publicId . '/reports/logo')->getStatusCode());
        foreach (['<svg onload="alert(1)"/>', 'not an image', str_repeat('x', 524289)] as $body) {
            try {
                $service->saveLogo($this->s->owner, $this->s->acmeProject, $body, \SeoControlCenter\Core\Support\ClientInfo::cli());
                self::fail('Unsafe logo accepted.');
            } catch (\SeoControlCenter\Domain\Shared\ValidationException) {
                self::assertSame($png, $this->reports->logo($this->s->acme->id)['body'] ?? null);
            }
        }
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/logo', ['remove_logo' => '1'])->getStatusCode());
        self::assertSame(404, $owner->get($this->url() . '/logo')->getStatusCode());
    }

    public function testDueReportsAreSentOnceAsPlainText(): void
    {
        $service = $this->s->container->get(ReportService::class);
        self::assertInstanceOf(ReportService::class, $service);
        $this->reports->save($this->s->acmeProject->id, $this->s->acme->id, 'weekly', ['client@shop.test'], true, $this->s->owner->id);
        $this->reports->publicIds[$this->s->acmeProject->id] = $this->s->acmeProject->publicId;

        self::assertSame(0, $service->dispatchDue(), 'Nothing goes out while e-mail is not set up.');
        $this->mailer->configured = true;
        self::assertSame(1, $service->dispatchDue());
        $job = $this->reports->queued[0];
        self::assertSame(['scheduled', '2026-09-27', ['client@shop.test']], [$job['kind'], $job['to'], $job['recipients']]);

        $service->send($job['workspaceId'], $job['project'], $job['frequency'], $job['to'], $job['recipients'], $job['kind']);
        self::assertCount(1, $this->mailer->sent);
        $message = $this->mailer->sent[0];
        self::assertSame('SEO report Acme Shop: Week 39 · 21–27 September 2026', $message->subject);
        self::assertStringContainsString('Clicks                   50  +25.0 %', $message->body);
        self::assertStringContainsString('1. =red shoes: 20 clicks', $message->body, 'Plain text needs no formula escaping.');
        self::assertStringContainsString('http://scc.test/projects/' . $this->s->acmeProject->publicId . '/reports/view?frequency=weekly&to=2026-09-27', $message->body);
        $html = (string) $message->html;
        self::assertStringContainsString('Acme Shop: Week 39 · 21–27 September 2026</h1>', $html, 'An HTML version comes along.');
        self::assertStringContainsString('=red shoes', $html);
        self::assertStringContainsString('href="http://scc.test/projects/' . $this->s->acmeProject->publicId . '/reports/view?frequency=weekly&amp;to=2026-09-27"', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html, 'No remote resources.');
        self::assertSame('2026-09-21..2026-09-27', $this->reports->find($this->s->acmeProject->id)?->lastPeriod);
        self::assertSame(0, $this->reports->deliveries($this->s->acmeProject->id, 5)[0]['failed']);

        self::assertSame(0, $service->dispatchDue(), 'A period goes out once.');
        $service->send($job['workspaceId'], $job['project'], $job['frequency'], $job['to'], $job['recipients'], $job['kind']);
        self::assertCount(1, $this->mailer->sent, 'A duplicate job sends nothing.');
    }

    public function testReportsCarryTheWhiteLabel(): void
    {
        $branding = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryBranding::class);
        $service = $this->s->container->get(ReportService::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemoryBranding::class, $branding);
        self::assertInstanceOf(ReportService::class, $service);
        $branding->save('Rank Radar', '#0f7b6c', 'Prepared by Radar Agency · hello@radar.example', $this->s->owner->id);
        $this->mailer->configured = true;

        $service->send($this->s->acme->id, $this->s->acmeProject->publicId, 'weekly', '2026-09-27', ['client@shop.test'], 'test');

        $message = $this->mailer->sent[0];
        self::assertStringEndsWith("--\nPrepared by Radar Agency · hello@radar.example\n", $message->body);
        self::assertStringNotContainsString('SEO Control Center', $message->body);
        $html = (string) $message->html;
        self::assertStringContainsString('font-weight:600;">Rank Radar</span>', $html);
        self::assertStringContainsString('background:#0f7b6c;', $html, 'The button uses the accent colour.');
        self::assertStringContainsString('Prepared by Radar Agency', $html);
        self::assertStringNotContainsString('SEO Control Center', $html);

        $page = (string) $this->s->loggedIn($this->s->viewer)->get($this->url() . '/view?frequency=weekly')->getBody();
        self::assertStringContainsString('<span>Rank Radar</span><span class="muted small">SEO report', $page);
        self::assertStringContainsString('Prepared by Radar Agency', $page);
    }

    public function testTestReportGoesToTheSignedInUserOnly(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(409, $owner->post($this->url() . '/test', ['frequency' => 'weekly'])->getStatusCode(), 'E-mail not set up.');

        $this->mailer->configured = true;
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/test', ['frequency' => 'monthly'])->getStatusCode());
        self::assertSame([[$this->s->owner->email], 'test', '2026-09-30'], [$this->reports->queued[0]['recipients'], $this->reports->queued[0]['kind'], $this->reports->queued[0]['to']]);
    }

    public function testOtherWorkspacesGetNotFound(): void
    {
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        foreach (['', '/view'] as $path) {
            self::assertSame(404, $rival->get($this->url() . $path)->getStatusCode(), $path);
        }
    }

    public function testBrowserFindingsReachPrintableAndEmailReportsWithoutHtmlAudit(): void
    {
        $repo = $this->s->container->make(\SeoControlCenter\Modules\Research\ResearchRepository::class);
        $repo->queue($this->s->acmeProject->id, $this->s->acme->id, 'rendered_audit', '', ['url' => 'https://shop.acme.test/'], null);
        $repo->complete($this->s->acmeProject->id, 'rendered_audit', '', [['url' => 'https://shop.acme.test/', 'findings' => ['Missing title']]]);
        $page = (string) $this->s->loggedIn($this->s->viewer)->get($this->url() . '/view?frequency=weekly')->getBody();
        self::assertStringContainsString('Browser:', $page);
        $this->mailer->configured = true;
        $this->s->container->make(ReportService::class)->send($this->s->acme->id, $this->s->acmeProject->publicId, 'weekly', '2026-09-27', ['client@shop.test'], 'test');
        self::assertStringContainsString('BROWSER AUDIT', $this->mailer->sent[0]->body);
        self::assertStringContainsString('Browser:', $this->mailer->sent[0]->body);
        $rival = (string) $this->s->loggedIn($this->s->rivalOwner)->get('/projects/' . $this->s->rivalProject->publicId . '/reports/view?frequency=weekly')->getBody();
        self::assertStringNotContainsString('Browser:', $rival);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/reports';
    }
}
