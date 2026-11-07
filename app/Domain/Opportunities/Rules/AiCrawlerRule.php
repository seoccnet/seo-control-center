<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\AiVisibility\AiCrawlers;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * AI crawler access (0.43.0): robots.txt keeps search and answer crawlers
 * (ChatGPT search, Perplexity, Claude, Google, Bing, Apple) out of the home
 * page or of top Search Console pages, so their answers cannot show or cite
 * those pages. Training crawlers are the owner's choice and never a finding.
 */
final class AiCrawlerRule implements OpportunityRule
{
    public const string SUBJECT = 'crawler:ai_search';

    public function requires(?Opportunity $opportunity = null): array
    {
        return ['ai_crawlers'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $check = $signals->aiCrawlers;
        $blocked = $check?->blockedSearch() ?? [];
        if ($check === null || $blocked === []) {
            return [];
        }
        $whole = array_values(array_filter($blocked, static fn(array $a): bool => $a['state'] === AiCrawlers::BLOCKED));
        $tokens = array_map(static fn(array $a): string => $a['token'], $blocked);
        $search = in_array('Googlebot', $tokens, true) || in_array('Bingbot', $tokens, true);
        $paths = array_values(array_unique(array_merge(...array_map(static fn(array $a): array => $a['blocked'], $blocked))));

        return [new Opportunity(
            OpportunityType::AiVisibility,
            self::SUBJECT,
            sprintf('robots.txt blocks %s', self::names($tokens)),
            sprintf(
                '%s%s. Pages a crawler may not fetch cannot be shown or cited in its answers. Allow these crawlers in robots.txt (a group naming them with "Allow: /", or remove the Disallow lines that match them), unless keeping them out is intended.',
                $whole !== [] ? 'robots.txt does not let ' . self::names(array_map(static fn(array $a): string => $a['token'], $whole)) . ' fetch your home page' : 'robots.txt keeps these crawlers out of some of your top Search Console pages',
                $whole !== [] && count($whole) < count($blocked) ? ', and keeps others out of some top pages' : '',
            ),
            [
                ['label' => 'Blocked crawlers', 'value' => implode(', ', array_map(static fn(array $a): string => $a['token'] . ($a['state'] === AiCrawlers::BLOCKED ? ' (whole site)' : ' (some top pages)'), $blocked))],
                ['label' => 'Top pages blocked', 'value' => $paths !== [] ? implode(', ', array_slice($paths, 0, 6)) . (count($paths) > 6 ? ' +' . (count($paths) - 6) : '') : '—'],
                ['label' => 'robots.txt', 'value' => $check->robotsUrl],
                ['label' => 'Checked', 'value' => substr($check->checkedAt, 0, 10)],
            ],
            $search ? 90 : ($whole !== [] ? 60 : 40),
            0,
            Opportunity::EFFORT_LOW,
        )];
    }

    /** @param list<string> $tokens */
    private static function names(array $tokens): string
    {
        return count($tokens) > 4 ? implode(', ', array_slice($tokens, 0, 4)) . ' and ' . (count($tokens) - 4) . ' more' : implode(', ', $tokens);
    }
}
