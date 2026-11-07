<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Assistant\AssistantDigestSummarizer;
use SeoControlCenter\Modules\Assistant\AssistantSettingsRepository;
use SeoControlCenter\Modules\Assistant\LanguageModels;
use SeoControlCenter\Tests\Support\Fakes\FakeLanguageModel;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAssistant;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * The AI summary of the weekly digest: one tool-less model call, booked
 * against the workspace's AI budget; null whenever it cannot be written.
 */
final class AssistantDigestSummaryTest extends TestCase
{
    private Scenario $s;

    private FakeLanguageModel $model;

    private InMemoryDataForSeo $store;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $model = $this->s->container->get(FakeLanguageModel::class);
        $store = $this->s->container->get(InMemoryDataForSeo::class);
        self::assertInstanceOf(FakeLanguageModel::class, $model);
        self::assertInstanceOf(InMemoryDataForSeo::class, $store);
        $this->model = $model;
        $this->store = $store;
    }

    public function testNotAvailableWithoutAssistant(): void
    {
        self::assertFalse($this->summarizer()->available($this->s->acme->id));
        self::assertNull($this->summarizer()->summarize($this->s->acme->id, '[]'));
        self::assertSame([], $this->model->calls);
    }

    public function testSummaryIsOneBookedCallWithoutTools(): void
    {
        $this->connect();
        $this->model->replies[] = FakeLanguageModel::text("  Clicks fell on Acme Shop.\n");

        self::assertTrue($this->summarizer()->available($this->s->acme->id));
        self::assertSame('Clicks fell on Acme Shop.', $this->summarizer()->summarize($this->s->acme->id, '[{"name":"Acme Shop"}]'));
        self::assertCount(1, $this->model->calls);
        self::assertSame([], $this->model->calls[0]['tools']);
        self::assertStringContainsString('weekly SEO e-mail digest', $this->model->calls[0]['system']);
        $usage = array_values(array_filter($this->store->usage, static fn(array $u): bool => $u['provider'] === 'ai_assistant'));
        self::assertSame([['charged', 'digest_summary.anthropic']], array_map(static fn(array $u): array => [$u['status'], $u['operation']], $usage));
    }

    public function testNullWhenTheModelFailsOrTheBudgetIsUsedUp(): void
    {
        $this->connect();
        $this->model->replies[] = new IntegrationException('Overloaded.', true);
        self::assertNull($this->summarizer()->summarize($this->s->acme->id, '[]'));

        $this->store->saveBudget($this->s->acme->id, 'ai_assistant', new ApiBudget(0.000001), $this->s->owner->id);
        self::assertNull($this->summarizer()->summarize($this->s->acme->id, '[]'));
        self::assertCount(1, $this->model->calls, 'No call over budget.');
    }

    private function connect(): void
    {
        $assistant = $this->s->container->get(InMemoryAssistant::class);
        self::assertInstanceOf(InMemoryAssistant::class, $assistant);
        $assistant->save($this->s->acme->id, 'anthropic', 'sk-test', 'claude-sonnet-5-5', $this->s->owner->id);
    }

    private function summarizer(): AssistantDigestSummarizer
    {
        $settings = $this->s->container->get(AssistantSettingsRepository::class);
        $models = $this->s->container->get(LanguageModels::class);
        $features = $this->s->container->get(FeatureManager::class);
        self::assertInstanceOf(AssistantSettingsRepository::class, $settings);
        self::assertInstanceOf(LanguageModels::class, $models);
        self::assertInstanceOf(FeatureManager::class, $features);

        return new AssistantDigestSummarizer($settings, $models, $this->store, $features, new NullLogger(), $this->s->container->make(\SeoControlCenter\Core\RateLimit\RateLimiter::class));
    }
}
