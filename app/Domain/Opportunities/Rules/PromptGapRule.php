<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\QueryPatterns;

/**
 * Prompts from the team's AI answer check (ADR-032, amended) where the
 * same competitor is named or cited by at least two AI platforms and the
 * project's brand and site appear in none of the answers. One answer per
 * platform is a sample, not a frequency: requiring agreement between
 * independent platforms keeps single odd answers out, confidence is capped
 * low and there is no click estimate.
 */
final class PromptGapRule implements OpportunityRule
{
    public const int MIN_PLATFORMS = 2;
    public const int LIMIT = 10;
    private const array PLATFORM_LABELS = ['gemini' => 'Gemini', 'perplexity' => 'Perplexity', 'chat_gpt' => 'ChatGPT', 'claude' => 'Claude'];
    private const array STOPWORDS = ['a', 'an', 'the', 'is', 'are', 'what', 'which', 'who', 'how', 'to', 'for', 'of', 'in', 'on', 'and', 'or', 'with', 'my', 'i', 'me', 'you', 'your', 'can', 'do', 'does', 'should', 'best', 'top', 'good', 'recommend', 'recommendation', 'some', 'any', 'it', 'be', 'that', 'this', 'there', 'get', 'from', 'about'];

    public function requires(?Opportunity $opportunity = null): array
    {
        return ['ai_answers'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        /** @var array<string, array{prompt: string, platforms: array<string, true>, present: bool, rivals: array<string, array<string, string>>}> $prompts */
        $prompts = [];
        foreach ($signals->aiAnswers as $answer) {
            $key = Keyword::normalize($answer['prompt']);
            $prompts[$key] ??= ['prompt' => $answer['prompt'], 'platforms' => [], 'present' => false, 'rivals' => []];
            $prompts[$key]['platforms'][$answer['platform']] = true;
            $prompts[$key]['present'] = $prompts[$key]['present'] || $answer['brand_mentioned'] || $answer['own_cited'];
            foreach ($answer['competitors'] as $rival) {
                if ($rival['mentioned'] || $rival['cited']) {
                    $prompts[$key]['rivals'][$rival['domain']][$answer['platform']] = $rival['mentioned'] && $rival['cited'] ? 'named and cited' : ($rival['cited'] ? 'cited' : 'named');
                }
            }
        }
        $found = [];
        foreach ($prompts as $key => $prompt) {
            if ($prompt['present'] || count($prompt['platforms']) < self::MIN_PLATFORMS) {
                continue;
            }
            $agreed = array_filter($prompt['rivals'], static fn(array $platforms): bool => count($platforms) >= self::MIN_PLATFORMS);
            if ($agreed === []) {
                continue;
            }
            uksort($agreed, static fn(string $a, string $b): int => [count($prompt['rivals'][$b]), $a] <=> [count($prompt['rivals'][$a]), $b]);
            $names = array_keys($agreed);
            $related = self::related($signals, $key);
            $found[] = [count($agreed), new Opportunity(
                OpportunityType::AiVisibility,
                'prompt:' . $key,
                sprintf('AI answers name %s, not you, for “%s”', implode(', ', array_slice($names, 0, 3)), $prompt['prompt']),
                'Independent AI assistants agree on recommending a competitor for this question and leave you out. Make your offer easy to recommend for it: a page that answers the question directly with facts AI can quote, comparison and "best of" coverage on other sites, reviews, and mentions in the sources the answers cite. Run the answer check again after changes; single answers vary.',
                [
                    ['label' => 'Prompt', 'value' => $prompt['prompt']],
                    ['label' => 'Answered by', 'value' => implode(', ', array_map(static fn(string $p): string => self::PLATFORM_LABELS[$p] ?? $p, array_keys($prompt['platforms'])))],
                    ['label' => 'Recommended instead', 'value' => implode('; ', array_map(static fn(string $domain, array $platforms): string => $domain . ': ' . implode(', ', array_map(static fn(string $p, string $how): string => (self::PLATFORM_LABELS[$p] ?? $p) . ' ' . $how, array_keys($platforms), $platforms)), array_keys($agreed), $agreed))],
                    ['label' => 'Your brand', 'value' => 'neither named nor cited in any of the answers'],
                    ['label' => 'Sample', 'value' => sprintf('One answer per platform, checked %s. Answers vary between runs: this is a sample of today\'s answers, not how often you are mentioned. Confidence is capped low and no clicks are estimated.', $signals->aiAnswersDate ?? 'on an unknown date')],
                    ['label' => 'Related keywords', 'value' => $related !== [] ? implode(', ', $related) : 'none of your tracked keywords or search queries share its main words'],
                ],
                (int) min(45, 30 + 5 * (count($agreed) - 1)),
                0,
                Opportunity::EFFORT_HIGH,
            )];
        }
        usort($found, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column(array_slice($found, 0, self::LIMIT), 1);
    }

    /**
     * Tracked keywords and search queries that share the prompt's main words:
     * the keyword targets this AI topic maps to.
     *
     * @return list<string>
     */
    public static function related(ProjectSignals $signals, string $prompt): array
    {
        $words = array_values(array_unique(array_diff(QueryPatterns::tokens($prompt), self::STOPWORDS)));
        if ($words === []) {
            return [];
        }
        $scores = [];
        foreach ([...array_map(static fn($k): string => $k->keyword, $signals->keywords), ...array_map(static fn($q): string => Keyword::normalize($q->key), $signals->queries)] as $keyword) {
            $tokens = array_values(array_unique(array_diff(QueryPatterns::tokens($keyword), self::STOPWORDS)));
            $shared = count(array_intersect($tokens, $words));
            if ($tokens !== [] && ($shared >= 2 || $shared >= 1 && $shared === count($tokens))) {
                $scores[$keyword] = $shared / count($tokens) + $shared;
            }
        }
        arsort($scores);

        return array_slice(array_keys($scores), 0, 5);
    }
}
