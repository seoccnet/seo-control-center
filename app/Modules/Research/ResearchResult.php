<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use SeoControlCenter\Domain\Backlinks\AnchorText;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Research\AiAnswer;
use SeoControlCenter\Domain\Research\AiMention;
use SeoControlCenter\Domain\Research\ResearchDomain;
use SeoControlCenter\Domain\Research\ResearchKeyword;

/**
 * The latest result of one research lookup of a project. While a new
 * lookup is queued, the previous rows stay readable.
 */
final readonly class ResearchResult
{
    public const array KINDS = ['ranked', 'gap', 'ideas', 'competitors', 'overview', 'llm', 'llm_prompts', 'llm_answers', 'suggestions', 'serp_strength', 'seed_ideas'];

    /**
     * @param array<string, mixed> $params
     * @param list<array<mixed>> $items
     */
    public function __construct(
        public int $projectId,
        public string $kind,
        public string $subject,
        public string $status,
        public array $params,
        public array $items,
        public ?string $error,
        public string $requestedAt,
        public ?string $fetchedAt,
    ) {}

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function progressNotice(): ?string
    {
        $batch = $this->params['_batch'] ?? null;
        if ($this->status === 'done' || !is_array($batch) || !is_array($batch['done'] ?? null)) {
            return null;
        }
        $saved = count($batch['done']);
        if (($batch['pending'] ?? null) !== null && $this->status === 'failed') {
            return $saved . ' completed requests saved. A request has an uncertain outcome; review DataForSEO usage before another paid lookup.';
        }
        if ($saved === 0) {
            return null;
        }
        return $saved . ' completed requests saved. Retries with the same settings continue the remaining requests without purchasing completed requests again.';
    }

    public function locale(): KeywordLocale
    {
        $location = $this->params['location'] ?? null;
        $language = $this->params['language'] ?? null;

        return is_int($location) && is_string($language) && isset(KeywordLocale::LOCATIONS[$location], KeywordLocale::LANGUAGES[$language])
            ? new KeywordLocale($location, $language)
            : KeywordLocale::default();
    }

    /**
     * @return list<ResearchKeyword>
     */
    public function keywords(): array
    {
        return array_values(array_filter(array_map(ResearchKeyword::fromArray(...), $this->items)));
    }

    /**
     * @return list<AiMention>
     */
    public function mentions(): array
    {
        return array_values(array_filter(array_map(AiMention::fromArray(...), $this->items)));
    }

    /**
     * @return list<AiAnswer>
     */
    public function answers(): array
    {
        return array_values(array_filter(array_map(AiAnswer::fromArray(...), $this->items)));
    }

    /**
     * Sampled AI answers (llm_answers) with what each says about the project.
     *
     * @return list<array{prompt: string, platform: string, model: string, web_search: bool, brand_mentioned: bool, own_cited: bool, competitors: list<array{domain: string, mentioned: bool, cited: bool}>, citations: list<array{url: string, title: ?string}>, text: string}>
     */
    public function samples(): array
    {
        $rows = [];
        foreach ($this->items as $item) {
            if (!is_array($item) || !is_string($item['prompt'] ?? null) || !is_string($item['platform'] ?? null) || !is_string($item['text'] ?? null)) {
                continue;
            }
            $competitors = [];
            foreach (is_array($item['competitors'] ?? null) ? $item['competitors'] : [] as $c) {
                if (is_array($c) && is_string($c['domain'] ?? null)) {
                    $competitors[] = ['domain' => $c['domain'], 'mentioned' => ($c['mentioned'] ?? false) === true, 'cited' => ($c['cited'] ?? false) === true];
                }
            }
            $citations = [];
            foreach (is_array($item['citations'] ?? null) ? $item['citations'] : [] as $c) {
                if (is_array($c) && is_string($c['url'] ?? null) && preg_match('#^https?://#i', $c['url']) === 1) {
                    $citations[] = ['url' => $c['url'], 'title' => is_string($c['title'] ?? null) ? $c['title'] : null];
                }
            }
            $rows[] = [
                'prompt' => $item['prompt'], 'platform' => $item['platform'], 'model' => is_string($item['model'] ?? null) ? $item['model'] : '',
                'web_search' => ($item['web_search'] ?? false) === true, 'brand_mentioned' => ($item['brand_mentioned'] ?? false) === true,
                'own_cited' => ($item['own_cited'] ?? false) === true, 'competitors' => $competitors, 'citations' => $citations, 'text' => $item['text'],
            ];
        }
        return $rows;
    }

    /**
     * Domain strength (0-100) of the result domains (serp_strength).
     *
     * @return array<string, int> bare domain => strength
     */
    public function strengths(): array
    {
        $strengths = [];
        foreach ($this->items as $item) {
            if (is_array($item) && is_string($item['d'] ?? null) && is_int($item['r'] ?? null)) {
                $strengths[$item['d']] = max(0, min(100, $item['r']));
            }
        }

        return $strengths;
    }

    /**
     * AI seed suggestions (seed_ideas): seed keywords and modifiers.
     *
     * @return array{seeds: list<string>, modifiers: list<string>, notes: string}
     */
    public function seedIdeas(): array
    {
        $first = is_array($this->items[0] ?? null) ? $this->items[0] : [];
        $list = static fn(mixed $v): array => is_array($v) ? array_values(array_filter($v, is_string(...))) : [];

        return ['seeds' => $list($first['seeds'] ?? null), 'modifiers' => $list($first['modifiers'] ?? null), 'notes' => is_string($first['notes'] ?? null) ? $first['notes'] : ''];
    }

    /**
     * @return list<BacklinkProfile>
     */
    public function backlinkProfiles(): array
    {
        return array_values(array_filter(array_map(BacklinkProfile::fromArray(...), $this->items)));
    }

    /**
     * @return list<ReferringDomain>
     */
    public function referringDomains(): array
    {
        return array_values(array_filter(array_map(ReferringDomain::fromArray(...), $this->items)));
    }

    /**
     * @return list<AnchorText>
     */
    public function anchors(): array
    {
        return array_values(array_filter(array_map(AnchorText::fromArray(...), $this->items)));
    }

    /** @return list<\SeoControlCenter\Domain\Backlinks\PageBacklink> */
    public function pageLinks(): array
    {
        return array_values(array_filter(array_map(\SeoControlCenter\Domain\Backlinks\PageBacklink::fromArray(...), $this->items)));
    }

    /**
     * @return list<ResearchDomain>
     */
    public function domains(): array
    {
        return array_values(array_filter(array_map(ResearchDomain::fromArray(...), $this->items)));
    }
}
