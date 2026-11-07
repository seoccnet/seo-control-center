<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\BacklinksProvider;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Research\AiMention;
use SeoControlCenter\Domain\Research\AiMentionsProvider;
use SeoControlCenter\Domain\Research\ResearchDomain;
use SeoControlCenter\Domain\Research\ResearchProvider;
use SeoControlCenter\Modules\Backlinks\BacklinksService;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Scenario;

final class ResumableBatchesTest extends TestCase
{
    public function testWeeklyAndMonthlySchedulersPreserveSafeProgressAndStopUncertainWork(): void
    {
        $project = $this->s->acmeProject;
        $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryAiMentionHistory::class)->setWeekly($project->id, $project->workspaceId, true);
        $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryBacklinkHistory::class)->setMonthly($project->id, $project->workspaceId, true);
        foreach (['llm', 'bl_profiles'] as $kind) {
            $service = $kind === 'llm' ? $this->s->container->make(ResearchService::class) : $this->s->container->make(BacklinksService::class);
            $schedule = $service instanceof ResearchService ? $service->startDueMentions(...) : $service->startDue(...);
            self::assertSame(1, $schedule());
            $result = $this->results->find($project->id, $kind);
            self::assertNotNull($result);
            (new \SeoControlCenter\Modules\Research\ResumableLookup($this->results, $result))->step('saved', static fn(): array => []);
            $this->results->fail($project->id, $kind, '', 'Safe retry limit.');
            self::assertSame(1, $schedule());
            $resumed = $this->results->find($project->id, $kind);
            self::assertNotNull($resumed);
            self::assertArrayHasKey('saved', $resumed->params['_batch']['done']);
            try {
                (new \SeoControlCenter\Modules\Research\ResumableLookup($this->results, $resumed))->step('uncertain', static fn(): array => throw new IntegrationException('Timeout.', true, chargeUncertain: true));
            } catch (IntegrationException) {
            }
            $this->results->fail($project->id, $kind, '', 'Uncertain request.');
            self::assertSame(0, $schedule());
            self::assertSame('failed', $this->results->find($project->id, $kind)?->status);
        }
    }

    private Scenario $s;
    private InMemoryResearch $results;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->results = $this->s->container->make(InMemoryResearch::class);
        $this->s->container->make(InMemoryCompetitors::class)->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
    }

    public function testOverviewResumesFromSecondDomainAfterQueueRetryLimit(): void
    {
        $provider = $this->createMock(ResearchProvider::class);
        $provider->method('isAvailable')->willReturn(true);
        $calls = [];
        $provider->expects(self::exactly(3))->method('overview')->willReturnCallback(static function (int $workspace, ?int $project, array $domains) use (&$calls): array {
            $calls[] = $domains;
            if (count($calls) === 2) {
                throw new IntegrationException('Rate limit', true);
            }
            return [new ResearchDomain($domains[0], 100, 5, 20, 10.5)];
        });
        $this->s->container->instance(ResearchProvider::class, $provider);
        $owner = $this->s->loggedIn($this->s->owner);
        $url = '/projects/' . $this->s->acmeProject->publicId . '/keywords/research';
        $input = ['kind' => 'overview', 'location' => '2840', 'language' => 'en'];
        $owner->get($url);
        self::assertSame(303, $owner->post($url . '/fetch', $input)->getStatusCode());
        $service = $this->s->container->make(ResearchService::class);
        $service->run($this->s->acmeProject->id, $this->s->acme->id, 'overview', '', true);
        self::assertSame('failed', $this->results->find($this->s->acmeProject->id, 'overview')?->status);
        $owner->get($url);
        self::assertSame(303, $owner->post($url . '/fetch', $input)->getStatusCode());
        $service->run($this->s->acmeProject->id, $this->s->acme->id, 'overview', '', false);
        self::assertSame([['shop.acme.test'], ['rival.test'], ['rival.test']], $calls);
        $result = $this->results->find($this->s->acmeProject->id, 'overview');
        self::assertNotNull($result);
        self::assertCount(2, $result->items);
    }

    public function testMentionsResumePerDomainAndPlatform(): void
    {
        $provider = $this->createMock(AiMentionsProvider::class);
        $calls = [];
        $provider->expects(self::exactly(5))->method('metrics')->willReturnCallback(static function (int $workspace, ?int $project, array $domains, string $platform) use (&$calls): array {
            $calls[] = [$domains[0], $platform];
            if (count($calls) === 3) {
                throw new IntegrationException('Rate limit', true);
            }
            return [new AiMention($domains[0], $platform, 1, 2)];
        });
        $this->s->container->instance(AiMentionsProvider::class, $provider);
        $project = $this->s->acmeProject;
        $this->results->queue($project->id, $project->workspaceId, 'llm', '', ['domain' => $project->primaryDomain, 'domains' => [$project->primaryDomain, 'rival.test']], null);
        $service = $this->s->container->make(ResearchService::class);
        try {
            $service->run($project->id, $project->workspaceId, 'llm', '', false);
            self::fail('Expected transient failure.');
        } catch (IntegrationException) {
        }
        $service->run($project->id, $project->workspaceId, 'llm', '', false);
        self::assertSame([['shop.acme.test', 'chat_gpt'], ['rival.test', 'chat_gpt'], ['shop.acme.test', 'google'], ['shop.acme.test', 'google'], ['rival.test', 'google']], $calls);
        $result = $this->results->find($project->id, 'llm');
        self::assertNotNull($result);
        self::assertCount(4, $result->items);
    }

    public function testBacklinkProfilesResumeWithoutRepeatingOwnDomain(): void
    {
        $provider = $this->createMock(BacklinksProvider::class);
        $calls = [];
        $provider->expects(self::exactly(3))->method('profiles')->willReturnCallback(static function (int $workspace, ?int $project, array $domains) use (&$calls): array {
            $calls[] = $domains;
            if (count($calls) === 2) {
                throw new IntegrationException('Rate limit', true);
            }
            return [new BacklinkProfile($domains[0], 30, 100, 20)];
        });
        $this->s->container->instance(BacklinksProvider::class, $provider);
        $project = $this->s->acmeProject;
        $this->results->queue($project->id, $project->workspaceId, 'bl_profiles', '', ['domain' => $project->primaryDomain, 'competitors' => ['rival.test']], null);
        $service = $this->s->container->make(BacklinksService::class);
        try {
            $service->run($project->id, $project->workspaceId, 'bl_profiles', false);
            self::fail('Expected transient failure.');
        } catch (IntegrationException) {
        }
        $service->run($project->id, $project->workspaceId, 'bl_profiles', false);
        self::assertSame([['shop.acme.test'], ['rival.test'], ['rival.test']], $calls);
        $result = $this->results->find($project->id, 'bl_profiles');
        self::assertNotNull($result);
        self::assertCount(2, $result->items);
    }

    public function testUncertainOverviewStopsAndBlocksIdenticalManualPurchase(): void
    {
        $provider = $this->createMock(ResearchProvider::class);
        $provider->method('isAvailable')->willReturn(true);
        $provider->expects(self::once())->method('overview')->willThrowException(new IntegrationException('Timeout; charge uncertain.', true, chargeUncertain: true));
        $this->s->container->instance(ResearchProvider::class, $provider);
        $owner = $this->s->loggedIn($this->s->owner);
        $url = '/projects/' . $this->s->acmeProject->publicId . '/keywords/research';
        $input = ['kind' => 'overview', 'location' => '2840', 'language' => 'en'];
        $owner->get($url);
        self::assertSame(303, $owner->post($url . '/fetch', $input)->getStatusCode());
        $service = $this->s->container->make(ResearchService::class);
        $service->run($this->s->acmeProject->id, $this->s->acme->id, 'overview', '', false);
        self::assertSame('failed', $this->results->find($this->s->acmeProject->id, 'overview')?->status);
        $page = (string) $owner->get($url)->getBody();
        self::assertStringContainsString('uncertain outcome', $page);
        self::assertSame(409, $owner->post($url . '/fetch', $input)->getStatusCode());
        $service->run($this->s->acmeProject->id, $this->s->acme->id, 'overview', '', false);
    }

    public function testRemovedCompetitorCancelsQueuedBatchBeforeAnyPaidRequest(): void
    {
        $provider = $this->createMock(ResearchProvider::class);
        $provider->expects(self::never())->method('overview');
        $this->s->container->instance(ResearchProvider::class, $provider);
        $project = $this->s->acmeProject;
        $this->results->queue($project->id, $project->workspaceId, 'overview', '', ['domain' => $project->primaryDomain, 'domains' => [$project->primaryDomain, 'rival.test']], null);
        $competitors = $this->s->container->make(InMemoryCompetitors::class);
        self::assertTrue($competitors->remove($project->id, $competitors->forProject($project->id)[0]->publicId));
        $this->s->container->make(ResearchService::class)->run($project->id, $project->workspaceId, 'overview', '', false);
        self::assertSame('failed', $this->results->find($project->id, 'overview')?->status);
    }
}
