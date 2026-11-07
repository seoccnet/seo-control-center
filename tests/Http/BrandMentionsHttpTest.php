<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Research\AiAnswer;
use SeoControlCenter\Modules\Research\BrandMentionService;
use SeoControlCenter\Tests\Support\Browser;
use SeoControlCenter\Tests\Support\Fakes\FakeAiMentionsProvider;
use SeoControlCenter\Tests\Support\Fakes\FakeBrandMentionScheduler;
use SeoControlCenter\Tests\Support\Scenario;

final class BrandMentionsHttpTest extends TestCase
{
    private Scenario $s;
    private Browser $browser;
    private BrandMentionService $service;
    private FakeAiMentionsProvider $provider;
    private FakeBrandMentionScheduler $scheduler;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $clock = new FrozenClock(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->s->container->instance(Clock::class, $clock);
        $this->s->container->instance(\SeoControlCenter\Modules\Research\BrandMentionRepository::class, new \SeoControlCenter\Tests\Support\Fakes\InMemoryBrandMentions($clock));
        $this->browser = $this->s->loggedIn($this->s->owner);
        $this->service = $this->s->container->make(BrandMentionService::class);
        $this->provider = $this->s->container->make(FakeAiMentionsProvider::class);
        $this->scheduler = $this->s->container->make(FakeBrandMentionScheduler::class);
        $this->browser->get($this->url());
    }

    public function testFourPersistedStepsDoNotRepeatAndNamesWithoutCitationsAreVisible(): void
    {
        $this->save();
        self::assertSame([], $this->provider->calls, 'saving never spends money');
        $this->provider->brandMetrics = ['chat_gpt' => ['mentions' => 42, 'ai_search_volume' => null], 'google' => ['mentions' => 17, 'ai_search_volume' => 900]];
        $this->provider->brandAnswers['chat_gpt'] = [new AiAnswer('chat_gpt', 'Which outdoor shop?', 'Acme & Co sells tents <script>bad</script>', 240, null, null, ['guide.test'], '2026-10-01')];
        self::assertSame(303, $this->browser->post($this->url() . '/brand/fetch')->getStatusCode());
        self::assertSame(409, $this->browser->post($this->url() . '/brand/fetch')->getStatusCode());
        $id = $this->scheduler->calls[0]['check'];
        for ($step = 0; $step < 4; ++$step) {
            $this->runStep($id, $step);
            $this->runStep($id, $step);
            self::assertCount($step + 1, $this->provider->calls, 'stale delivery must not bill twice');
        }
        self::assertSame(['brandMetrics', 'brandAnswers', 'brandMetrics', 'brandAnswers'], array_column($this->provider->calls, 'call'));
        self::assertSame('2276:de', $this->provider->calls[0]['args'][4]);
        $page = (string) $this->browser->get($this->url())->getBody();
        self::assertStringContainsString('Latest complete result:', $page);
        self::assertStringContainsString('No project-domain citation in this sample', $page);
        self::assertStringContainsString('2026-10-01', $page);
        self::assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $page);
        self::assertStringNotContainsString('<script>bad</script>', $page);
        self::assertStringContainsString('do not add their counts', $page);
        self::assertContains('research.brand_requested', $this->s->audit()->actions());
    }

    public function testFailureKeepsThePreviousCompleteResultAndDoesNotRetry(): void
    {
        $this->save();
        $this->browser->post($this->url() . '/brand/fetch');
        $id = $this->scheduler->calls[0]['check'];
        for ($step = 0; $step < 4; ++$step) {
            $this->runStep($id, $step);
        }
        $this->browser->post($this->url() . '/brand/fetch');
        $next = $this->scheduler->calls[4]['check'];
        $this->runStep($next, 0);
        $this->provider->brandFailure = new IntegrationException('Provider unavailable', true);
        $this->runStep($next, 1);
        $this->runStep($next, 1);
        $data = $this->service->page($this->s->acmeProject);
        self::assertSame($id, $data['latest']?->publicId);
        self::assertSame('failed', $data['attempt']?->status);
        self::assertCount(6, $this->provider->calls);
        self::assertCount(6, $this->scheduler->calls);
    }

    public function testWeeklyIsSeparateOptInAndRevokedAccessOrChangedSettingsStopQueuedCalls(): void
    {
        $this->save();
        self::assertSame(0, $this->service->startDue());
        $this->save('Acme & Co', true);
        self::assertSame(1, $this->service->startDue());
        self::assertSame(0, $this->service->startDue());
        $id = $this->scheduler->calls[0]['check'];
        $this->save('Acme & Co', false);
        $this->runStep($id, 0);
        self::assertSame([], $this->provider->calls);
        self::assertSame('failed', $this->service->page($this->s->acmeProject)['attempt']?->status);
        $this->save('Different Brand', true);
        self::assertSame([], $this->service->page($this->s->acmeProject)['history']);
        self::assertSame(1, $this->service->startDue());
        $id = $this->scheduler->calls[1]['check'];
        $this->s->dir->removeMember($this->s->acme->id, $this->s->owner->id);
        $this->runStep($id, 0);
        self::assertSame([], $this->provider->calls);
    }

    public function testInterruptedProcessingCannotBeAutomaticallyResent(): void
    {
        $this->save();
        $this->browser->post($this->url() . '/brand/fetch');
        $check = $this->service->page($this->s->acmeProject)['attempt'];
        self::assertNotNull($check);
        $repo = $this->s->container->make(\SeoControlCenter\Modules\Research\BrandMentionRepository::class);
        self::assertTrue($repo->claim($this->s->acmeProject, $check));
        $this->runStep($check->publicId, 0);
        self::assertSame([], $this->provider->calls);
        self::assertSame(409, $this->browser->post($this->url() . '/brand/fetch')->getStatusCode());
        $clock = $this->s->container->make(Clock::class);
        self::assertInstanceOf(FrozenClock::class, $clock);
        $clock->advance('+25 hours');
        $this->browser = $this->s->loggedIn($this->s->owner);
        $this->browser->get($this->url());
        self::assertSame(303, $this->browser->post($this->url() . '/brand/fetch')->getStatusCode());
        self::assertSame('failed', $repo->find($this->s->acmeProject, $check->publicId)?->status, 'new check supersedes abandoned processing');
        $this->runStep($check->publicId, 0);
        self::assertSame([], $this->provider->calls);
    }

    public function testValidationCsrfAndProviderAvailability(): void
    {
        self::assertSame(419, $this->browser->post($this->url() . '/brand', [], withCsrf: false)->getStatusCode());
        $this->browser->get($this->url());
        self::assertSame(422, $this->browser->post($this->url() . '/brand', ['brand' => 'Acme', 'location' => ['invalid'], 'language' => ['invalid']])->getStatusCode());
        self::assertSame(422, $this->browser->post($this->url() . '/brand', ['brand' => '<script>', 'location' => '2840', 'language' => 'en'])->getStatusCode());
        $this->save('', false);
        self::assertSame(409, $this->browser->post($this->url() . '/brand/fetch')->getStatusCode());
        $this->save();
        $this->provider->available = false;
        self::assertSame(409, $this->browser->post($this->url() . '/brand/fetch')->getStatusCode());
        self::assertSame([], $this->scheduler->calls);
    }

    private function save(string $name = 'Acme & Co', bool $weekly = false): void
    {
        $this->browser->get($this->url());
        self::assertSame(303, $this->browser->post($this->url() . '/brand', ['brand' => $name, 'location' => '2276', 'language' => 'de', 'weekly' => $weekly ? '1' : '0'])->getStatusCode());
    }
    private function runStep(string $id, int $step): void
    {
        $this->service->run($this->s->acme->id, $this->s->acmeProject->publicId, $id, $step);
    }
    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/ai-visibility';
    }
}
