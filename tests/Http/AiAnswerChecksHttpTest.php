<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Research\SampledAnswer;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Tests\Support\Fakes\FakeAiAnswerSampler;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * AI answer checks: the user's prompts sent to Gemini and Perplexity, with
 * what each answer says about the brand, the site and competitors.
 */
final class AiAnswerChecksHttpTest extends TestCase
{
    private Scenario $s;
    private FakeAiAnswerSampler $sampler;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->sampler = $this->s->container->get(FakeAiAnswerSampler::class);
        $this->s->container->get(InMemoryCompetitors::class)->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/ai-visibility';
    }

    private function fetch(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/keywords/research/fetch';
    }

    public function testPromptsAreSampledOnBothPlatformsAndAnalysed(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Answers to your prompts', $page);
        self::assertStringContainsString('name="prompts"', $page);
        self::assertStringContainsString('at most $0.12 per prompt', $page);

        $cases = [
            [['prompts' => '', 'brand' => 'Acme'], 'Enter between 1 and 10 prompts'],
            [['prompts' => implode('
', array_map(static fn(int $i): string => 'prompt ' . $i, range(1, 11))), 'brand' => 'Acme'], 'Enter between 1 and 10 prompts'],
            [['prompts' => str_repeat('x', 501), 'brand' => 'Acme'], 'at most 500 characters'],
            [['prompts' => 'ok', 'brand' => ''], 'Enter your brand name'],
        ];
        foreach ($cases as [$bad, $message]) {
            $owner->get($this->url());
            self::assertSame(303, $owner->post($this->fetch(), ['kind' => 'llm_answers', 'location' => '2840', 'language' => 'en', ...$bad])->getStatusCode());
            self::assertStringContainsString($message, (string) $owner->get($this->url())->getBody());
        }
        self::assertNull($this->s->container->get(InMemoryResearch::class)->find($this->s->acmeProject->id, 'llm_answers'), 'Invalid input queues nothing.');

        $this->sampler->answers['gemini|Best red shoes?'] = new SampledAnswer('gemini', 'gemini-2.5-flash', 'Best red shoes?', "ACME has <b>great</b> shoes;\nRival too.", [['url' => 'https://www.shop.acme.test/red', 'title' => 'Acme'], ['url' => 'https://rival.test/', 'title' => 'Rival']], true);
        $this->sampler->answers['perplexity|Best red shoes?'] = new SampledAnswer('perplexity', 'sonar', 'Best red shoes?', 'Try the rival store.', [['url' => 'https://rival.test/shoes', 'title' => null]], true);
        $this->sampler->failOnce = 'perplexity|Cheap shoes?';
        $owner->get($this->url());
        $response = $owner->post($this->fetch(), ['kind' => 'llm_answers', 'location' => '2840', 'language' => 'en', 'brand' => 'Acme', 'prompts' => "Best red shoes?\n\nbest RED shoes?\nCheap shoes?"]);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringEndsWith('/ai-visibility#assistants', $response->getHeaderLine('Location'));
        $queued = $this->s->container->get(InMemoryResearch::class)->find($this->s->acmeProject->id, 'llm_answers');
        self::assertSame(['Best red shoes?', 'Cheap shoes?'], $queued?->params['prompts'] ?? null, 'Blank lines and case-insensitive duplicates are dropped.');

        $research = $this->s->container->get(ResearchService::class);
        try {
            $research->run($this->s->acmeProject->id, $this->s->acme->id, 'llm_answers', '', false);
            self::fail('A temporary provider error is retried by the queue.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
        }
        $research->run($this->s->acmeProject->id, $this->s->acme->id, 'llm_answers', '', false);
        $calls = array_map(static fn(array $c): string => $c['platform'] . '|' . $c['prompt'], $this->sampler->calls);
        self::assertSame(['gemini|Best red shoes?', 'gemini|Cheap shoes?', 'perplexity|Best red shoes?', 'perplexity|Cheap shoes?', 'perplexity|Cheap shoes?'], $calls, 'The retry pays only for the failed answer.');

        $result = $this->s->container->get(InMemoryResearch::class)->find($this->s->acmeProject->id, 'llm_answers');
        self::assertNotNull($result);
        $samples = $result->samples();
        self::assertCount(4, $samples);
        self::assertSame([true, true, [['domain' => 'rival.test', 'mentioned' => true, 'cited' => true]]], [$samples[0]['brand_mentioned'], $samples[0]['own_cited'], $samples[0]['competitors']]);
        self::assertSame([false, false, [['domain' => 'rival.test', 'mentioned' => true, 'cited' => true]]], [$samples[2]['brand_mentioned'], $samples[2]['own_cited'], $samples[2]['competitors']]);

        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('<span class="badge badge-ok">Named</span>', $page);
        self::assertStringContainsString('<span class="badge badge-ok">Cited</span>', $page);
        self::assertStringContainsString('rival.test (cited)', $page);
        self::assertStringContainsString('ACME has &lt;b&gt;great&lt;/b&gt; shoes;<br />', $page, 'Answer text is escaped.');
        self::assertStringNotContainsString('<b>great</b>', $page);
        self::assertStringContainsString('<a href="https://rival.test/" rel="noopener noreferrer nofollow">Rival</a>', $page);
        self::assertStringContainsString('Best red shoes?' . "\nCheap shoes?", $page, 'The form keeps the last prompts.');
        self::assertSame([], AccessibilityTest::problems($page, true));
    }

    public function testOnlyAnalystsCanRunChecksAndOtherWorkspacesGetNotFound(): void
    {
        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringNotContainsString('name="prompts"', (string) $viewer->get($this->url())->getBody());
        self::assertSame(403, $viewer->post($this->fetch(), ['kind' => 'llm_answers', 'location' => '2840', 'language' => 'en', 'brand' => 'Acme', 'prompts' => 'x'])->getStatusCode());
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rival->get('/account');
        self::assertSame(404, $rival->post($this->fetch(), ['kind' => 'llm_answers', 'location' => '2840', 'language' => 'en', 'brand' => 'Acme', 'prompts' => 'x'])->getStatusCode());
        self::assertSame([], $this->sampler->calls);
        $this->sampler->available = false;
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringNotContainsString('name="prompts"', (string) $owner->get($this->url())->getBody(), 'No form without a connected provider.');
    }
}
