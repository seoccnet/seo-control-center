<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * SEOcc's own action plans per kind of finding (ADR-031). Each plan follows the
 * same shape (when it applies, before you start, what to change, what to keep,
 * what to measure, when to revert) so a change can be tested against an
 * untouched comparison set and rolled back. Text is filled in with the
 * finding's query and page where known. Plans are guidance only and never
 * feed scoring.
 */
final class PlaybookLibrary
{
    private const string MEASURE_WINDOW = 'Compare the 28 days before the change with the 28 days starting one week after it, so the page has time to be recrawled.';
    /**
     * How well tested each plan's kind of change is (0.41.0; Playbook::TESTED), from what published
     * tests have measured. Keys missing here, such as most technical issues, follow search engine guidance.
     */
    private const array TESTED = [
        'low_ctr' => 'tested', 'quick_win' => 'tested', 'ranking_decline' => 'tested', 'traffic_decline' => 'tested', 'content_refresh' => 'tested',
        'content_gap' => 'tested', 'keyword_gap' => 'tested', 'competitor_gap' => 'tested', 'cannibalization' => 'tested', 'internal_links' => 'tested', 'internal_links:pillar' => 'tested',
        'link_building' => 'tested', 'ai_visibility:aio' => 'tested', 'ai_visibility' => 'tested', 'local_seo' => 'tested', 'local_seo:nap' => 'tested', 'local_seo:category' => 'tested',
        'site:missing_structured_data' => 'tested', 'site:missing_author' => 'tested', 'site:missing_sources' => 'tested',
        'site:missing_content_markup' => 'tested', 'site:missing_questions' => 'tested', 'site:missing_spec_table' => 'tested', 'cannibalization:duplicate' => 'varies',
        'link_building:spam' => 'varies', 'site:deep_page' => 'varies', 'core_web_vitals' => 'varies', 'traffic_decline:surface' => 'varies',
        'link_building:lost' => 'early', 'ai_visibility:prompt' => 'early', 'click_erosion' => 'early', 'format_mismatch' => 'early', 'tool_idea' => 'early',
    ];

    /**
     * @param list<array{label: string, value: string}> $evidence
     */
    public function for(OpportunityType $type, string $subject, array $evidence = []): Playbook
    {
        $key = self::key($type, $subject);
        if ($key === 'indexing' && self::value($evidence, ['Google-selected canonical']) !== null) {
            $key = 'indexing:canonical'; // the page's own finding, with Google indexing another URL
        }

        return $this->forKey($key, $evidence);
    }

    /**
     * The playbook of a key (as key() gives it), for example to name the
     * plan a change bundle was started from (0.45.0, the results ledger).
     * Without evidence its text refers to "the page" and "the target query".
     *
     * @param list<array{label: string, value: string}> $evidence
     */
    public function forKey(string $key, array $evidence = []): Playbook
    {
        $query = self::value($evidence, ['Query', 'Keyword']);
        $page = self::value($evidence, ['Page', 'Ranking URL', 'URL', 'Value page']);
        if ($page !== null) {
            $page = explode(' (', $page, 2)[0];
        }
        // Page-level findings have no single query: refer to the page's queries instead.
        $q = $query !== null ? '“' . $query . '”' : ($page !== null ? 'the page’s main search queries' : 'the target query');
        $p = $page ?? 'the page';
        $plan = match (true) {
            $key === 'low_ctr' => self::lowCtr($q, $p),
            $key === 'quick_win:brand' => self::brandSearch($query ?? 'your brand name'),
            $key === 'quick_win' => self::quickWin($q, $p),
            $key === 'ranking_decline' => self::rankingDecline($q, $p),
            $key === 'traffic_decline' => self::trafficDecline($p),
            $key === 'content_refresh' => self::contentRefresh($q, $p, self::value($evidence, ['Last updated'])),
            $key === 'content_gap' => self::contentGap($q),
            $key === 'keyword_gap' => self::keywordGap($q),
            $key === 'competitor_gap' => self::competitorGap($q, $p),
            $key === 'cannibalization:duplicate' => self::mergeDuplicates($p, self::value($evidence, ['Merge into it']) ?? 'the other page'),
            $key === 'cannibalization' => self::cannibalization($q),
            $key === 'internal_links' => self::internalLinks($p),
            $key === 'internal_links:pillar' => self::pillarLinks($p, self::value($evidence, ['Topic']) ?? 'the topic', self::value($evidence, ['Pages without a link in the text']) ?? 'the topic’s other pages'),
            $key === 'link_building:lost' => self::lostLink($p),
            $key === 'link_building:spam' => self::spamLinks(),
            $key === 'link_building' => self::linkBuilding($p),
            $key === 'ai_visibility:aio' => self::aiOverview($q, $p),
            $key === 'ai_visibility:crawler' => self::aiCrawlers(self::value($evidence, ['Blocked crawlers']) ?? 'the AI search crawlers'),
            $key === 'ai_visibility:prompt' => self::promptGap(self::value($evidence, ['Prompt']) ?? 'the question', self::value($evidence, ['Recommended instead']) ?? 'the competitors named'),
            $key === 'click_erosion' => self::clickErosion($q, $p),
            $key === 'format_mismatch' => self::formatMismatch($q, $p, self::value($evidence, ['Recommended format'])),
            $key === 'tool_idea' => self::toolIdea($q),
            $key === 'ai_visibility' => self::aiMentions($query !== null ? $q : 'your main topics'),
            $key === 'local_seo:nap' => self::napConsistency(self::value($evidence, ['Differs or missing']) ?? 'name, address or phone'),
            $key === 'local_seo:replies' => self::reviewReplies(),
            $key === 'local_seo:category' => self::localCategory(self::value($evidence, ['Leading category']) ?? 'the category the local pack uses', self::value($evidence, ['Your category']) ?? 'your category'),
            $key === 'local_seo' => self::localSeo($query !== null ? $q : 'your local searches', $p),
            $key === 'indexing:canonical' => self::canonical($p, self::value($evidence, ['Google-selected canonical']) ?? 'the URL Google chose'),
            $key === 'indexing:rich' => self::richResults($p),
            $key === 'indexing:sitemap' => self::sitemap(self::value($evidence, ['Sitemap']) ?? 'the sitemap'),
            $key === 'indexing:bing' => self::bingCrawl(),
            $key === 'indexing' => self::indexing($p),
            $key === 'traffic_decline:surface' => self::surfaceDecline(self::value($evidence, ['Surface']) ?? 'the surface'),
            $key === 'core_web_vitals' => self::coreWebVitals($p),
            str_starts_with($key, 'site:') => self::siteIssue(substr($key, 5)),
            default => self::technical(),
        };
        $format = self::value($evidence, ['Recommended format']);
        if ($format !== null && in_array($key, ['quick_win', 'content_gap', 'keyword_gap', 'competitor_gap', 'ranking_decline'], true) && is_array($plan['change'] ?? null)) {
            // The results page shows which kind of page wins; content work on the wrong kind rarely pays off.
            array_unshift($plan['change'], 'Match the kind of page that ranks for ' . $q . ': ' . $format . '.');
        }
        return new Playbook($key, self::text($plan['title'] ?? ''), self::text($plan['goal'] ?? ''), self::sections($plan), self::strings($plan['cautions'] ?? []), self::strings($plan['also'] ?? []), self::TESTED[$key] ?? 'guidance');
    }

    /**
     * The playbook key of a finding. Site issues use their issue type so
     * each technical problem gets its own steps; CrUX findings get the Core
     * Web Vitals plan.
     */
    public static function key(OpportunityType $type, string $subject): string
    {
        return match ($type) {
            OpportunityType::LowCtr => 'low_ctr',
            OpportunityType::QuickWin => str_starts_with($subject, 'brand:') ? 'quick_win:brand' : 'quick_win',
            OpportunityType::RankingDecline => 'ranking_decline',
            OpportunityType::TrafficDecline => str_starts_with($subject, 'surface:') ? 'traffic_decline:surface' : 'traffic_decline',
            OpportunityType::ContentRefresh => 'content_refresh',
            OpportunityType::ContentGap => 'content_gap',
            OpportunityType::KeywordGap => 'keyword_gap',
            OpportunityType::CompetitorGap => 'competitor_gap',
            OpportunityType::Cannibalization => str_starts_with($subject, 'duplicate:') ? 'cannibalization:duplicate' : 'cannibalization',
            OpportunityType::InternalLinks => str_starts_with($subject, 'pillar:') ? 'internal_links:pillar' : 'internal_links',
            OpportunityType::LinkBuilding => match (true) {
                str_starts_with($subject, 'lost:') => 'link_building:lost',
                str_contains($subject, 'spam') => 'link_building:spam',
                default => 'link_building',
            },
            OpportunityType::AiVisibility => match (true) {
                str_starts_with($subject, 'aio:') => 'ai_visibility:aio',
                str_starts_with($subject, 'prompt:') => 'ai_visibility:prompt',
                str_starts_with($subject, 'crawler:') => 'ai_visibility:crawler',
                default => 'ai_visibility',
            },
            OpportunityType::ClickErosion => 'click_erosion',
            OpportunityType::FormatMismatch => 'format_mismatch',
            OpportunityType::ToolIdea => 'tool_idea',
            OpportunityType::LocalSeo => match ($subject) {
                'gbp:nap' => 'local_seo:nap',
                'gbp:replies' => 'local_seo:replies',
                'gbp:category' => 'local_seo:category',
                default => 'local_seo',
            },
            OpportunityType::Indexing => match (true) {
                str_starts_with($subject, 'indexing:rich:') => 'indexing:rich',
                str_starts_with($subject, 'indexing:sitemap:') => 'indexing:sitemap',
                str_starts_with($subject, 'indexing:bing:') => 'indexing:bing',
                default => 'indexing',
            },
            OpportunityType::SiteIssue => match (true) {
                str_starts_with($subject, 'crux:') => 'core_web_vitals',
                str_starts_with($subject, 'site:') && isset(self::SITE_ISSUES[substr($subject, 5)]) => $subject,
                default => 'site_issue',
            },
        };
    }

    /**
     * @param list<array{label: string, value: string}> $evidence
     * @param list<string> $labels
     */
    private static function value(array $evidence, array $labels): ?string
    {
        foreach ($evidence as $item) {
            if (in_array($item['label'], $labels, true) && trim($item['value']) !== '' && $item['value'] !== '—') {
                return trim($item['value']);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<array{label: string, items: list<string>}>
     */
    private static function sections(array $plan): array
    {
        $sections = [];
        foreach (['applies', 'before', 'change', 'keep', 'measure', 'revert'] as $i => $key) {
            $items = self::strings($plan[$key] ?? []);
            if ($items !== []) {
                $sections[] = ['label' => Playbook::SECTIONS[$i], 'items' => $items];
            }
        }
        return $sections;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Pages that still earn search demand but have not been updated for a year (0.41.0). Of the content
     * changes published tests have measured, refreshing facts and expanding sections is the best supported.
     *
     * @return array<string, mixed>
     */
    private static function contentRefresh(string $q, string $p, ?string $updated): array
    {
        return [
            'title' => 'Refresh an aging page before it slips',
            'goal' => 'Bring ' . $p . ' up to date for ' . $q . ', so it keeps the visibility it earns now.',
            'applies' => ['The page still gets impressions, but its content was last updated ' . ($updated !== null ? 'on ' . $updated : 'over a year ago') . '. Pages like this tend to lose ground to fresher results over time.'],
            'before' => [
                'Note the page’s clicks, impressions and position for the last 28 days, and pick a few similar pages you will leave unchanged as a comparison set.',
                'Read the current top results for ' . $q . ' and list the facts, numbers and subtopics they cover that the page does not.',
            ],
            'change' => [
                'Replace outdated figures, prices, dates and claims with current ones, citing where each comes from.',
                'Add sections for the subtopics the current top results cover and the page misses; cut passages that are no longer true.',
                'Update the title and description only where the content really changed, and change the visible update date only together with a real update.',
            ],
            'keep' => ['Keep the URL, the parts that already rank and the internal links pointing to the page.'],
            'measure' => [self::MEASURE_WINDOW, 'Watch clicks, impressions and the page’s main queries, against the comparison set.'],
            'revert' => ['If clicks and impressions fall clearly below the comparison set over the following 28 days, restore the previous version and review what was removed.'],
            'cautions' => ['Do not only change the date: a new date without a real update is not a refresh.'],
            'also' => ['Add a short summary of what changed near the top for returning readers.'],
        ];
    }

    /**
     * Supporting pages of a topic that never link to its main page (0.42.0).
     *
     * @return array<string, mixed>
     */
    private static function pillarLinks(string $p, string $topic, string $pages): array
    {
        return [
            'title' => 'Link a topic’s pages to its main page',
            'goal' => 'Make ' . $p . ' the clear main page for “' . $topic . '” by linking to it from the topic’s other pages.',
            'applies' => ['Topics & owners names ' . $p . ' as the main page for “' . $topic . '”, and these pages are shown for the topic but do not link to it from their text: ' . $pages . '.'],
            'before' => ['Note the main page’s position and impressions for the topic’s searches over the last 28 days, and pick one or two other topics you leave unchanged as a comparison.'],
            'change' => [
                'In the text of each page listed, add one link to ' . $p . ' where the topic comes up, with words that describe the topic rather than “click here”.',
                'From ' . $p . ', link back to each supporting page where it adds detail, so readers and crawlers can move both ways.',
            ],
            'keep' => ['Keep the supporting pages’ own focus: link to the main page, do not merge or rewrite them for its keyword.'],
            'measure' => [self::MEASURE_WINDOW, 'Watch the main page’s position and impressions for the topic’s searches, against the comparison topics.'],
            'revert' => ['If the main page or a supporting page loses clearly more than the comparison over 28 days, remove the new links again.'],
            'cautions' => ['Do not add the same exact-match anchor on every page; vary the wording naturally.'],
            'also' => ['Add a short “related guides” block at the end of each supporting page that points to the main page.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function lowCtr(string $q, string $p): array
    {
        return [
            'title' => 'Earn more clicks from the rankings you already have',
            'goal' => 'Make the search result for ' . $q . ' the most relevant and specific one on the page, without losing the position it already holds.',
            'applies' => ['The page already ranks on page one for ' . $q . ' but gets fewer clicks than its position normally earns.'],
            'before' => [
                'Search ' . $q . ' in a private window and note what the competing titles and descriptions promise, and which rich results (stars, prices, FAQs, video) appear.',
                'Check the query intent: if it is navigational for a competitor brand, a better snippet will not win many clicks; focus elsewhere.',
                'Record the current title, description, clicks, impressions, CTR and average position for ' . $p . '.',
            ],
            'change' => [
                'Rewrite the title tag of ' . $p . ': lead with the words of ' . $q . ', add the concrete benefit or differentiator (type, range, year, use case) and keep it under about 60 characters.',
                'Rewrite the meta description as a short answer plus a reason to click: what the visitor gets, a specific detail and a clear next step, about 140–155 characters.',
                'Make the H1 and the first paragraph match the promise of the new title so the visit confirms the click.',
                'Where the page qualifies, add or fix structured data that earns rich results (Product with price and availability, Review/AggregateRating, Breadcrumb, Article).',
            ],
            'keep' => ['Keep the URL, the main content, internal links and the page template unchanged during the test, so a change in clicks can be attributed to the snippet.'],
            'measure' => ['Track CTR and clicks for ' . $q . ' and for the page as a whole, at similar average positions. ' . self::MEASURE_WINDOW, 'Check whether Google shows your new title; if it rewrites it, the title did not match the page well enough.'],
            'revert' => ['If CTR falls at a similar position after four weeks, restore the previous title and description and try a different angle.'],
            'cautions' => [...SeoGuardrails::TITLES, 'Avoid all-caps titles; they look spammy and are often rewritten by Google.'],
            'also' => ['Test a question-style title for informational queries.', 'Add a short FAQ-style answer near the top if the query is a question.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function brandSearch(string $search): array
    {
        return [
            'title' => 'Rank first for your own brand',
            'goal' => 'Be the first result when people search “' . $search . '”.',
            'applies' => ['Your site is not first, on average, for a search that names your brand.'],
            'before' => ['Search “' . $search . '” yourself, in a private window and in your main market, and note what ranks above you: a marketplace, a review or coupon site, an old or duplicate page of your own, or a business with a similar name.'],
            'change' => [
                'Put the brand name as people write it, including the spelling in this search, in the home page title and main heading.',
                'Add Organization markup on the home page with the name, logo, website and your social profiles in sameAs.',
                'Link to the home page from your social profiles, marketplace listings and directories with the same name.',
                'If an old page of your own ranks above the home page, redirect it or link it clearly to the home page.',
            ],
            'keep' => ['Keep the brand name consistent everywhere; do not chase a misspelling by renaming the business.'],
            'measure' => ['Follow the average position and clicks for this search in Search Console over 4–8 weeks.'],
            'revert' => [],
            'cautions' => ['A marketplace or review site that sells or reviews your products may rank for your name legitimately; aim to be above it, not to remove it.'],
            'also' => ['Claim a Google Business Profile if customers can visit or call you.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function quickWin(string $q, string $p): array
    {
        return [
            'title' => 'Push a near-top ranking into the top three',
            'goal' => 'Make ' . $p . ' the clearly best answer for ' . $q . ' so it can move from positions 4–20 into the top three.',
            'applies' => ['The page ranks between positions 4 and 20 for ' . $q . ' and the query has real demand.'],
            'before' => [
                'Open the top three results for ' . $q . ' and list what they cover that ' . $p . ' does not: subtopics, data, examples, comparisons, media and freshness.',
                'Confirm ' . $p . ' is the right page for the intent; if another page of yours fits better, improve that one instead.',
            ],
            'change' => [
                'Update the title, H1 and opening paragraph so they answer ' . $q . ' directly in the first sentences.',
                'Add the missing subtopics as clear H2 sections, with current facts, specific numbers and short examples; remove outdated or padded passages.',
                'Use lists or a table where visitors compare options, and add a call to action that fits the intent.',
                'Add three to five contextual internal links to ' . $p . ' from related, already visible pages, using descriptive anchor text close to ' . $q . '.',
            ],
            'keep' => ['Keep the URL and the parts of the page that already rank well; refresh rather than rewrite from scratch.'],
            'measure' => ['Track the average position and clicks for ' . $q . ' and related queries of the page. ' . self::MEASURE_WINDOW],
            'revert' => ['If the position drops clearly and stays down after four weeks, restore the earlier version and change one element at a time.'],
            'cautions' => ['Do not repeat the keyword unnaturally; cover the topic with related terms and entities instead.', SeoGuardrails::PAGES[3]],
            'also' => ['Earn one or two relevant external links to the page.', 'Improve the snippet once the position rises.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function rankingDecline(string $q, string $p): array
    {
        return [
            'title' => 'Recover a lost ranking',
            'goal' => 'Find out why ' . $q . ' lost positions and restore the page’s relevance and freshness.',
            'applies' => ['A tracked keyword dropped by several positions or left the top results.'],
            'before' => [
                'Check the change timeline for edits, migrations or template changes around the date of the drop.',
                'Search ' . $q . ' and see which pages now rank above you and what changed in the results (new formats, AI Overviews, intent shift).',
                'Check that ' . $p . ' is still indexable: status 200, no noindex, canonical to itself, not blocked by robots.txt.',
            ],
            'change' => [
                'Fix any technical cause first (redirects, canonical, noindex, slow or broken template).',
                'Update the facts and examples on the page, add sections for subtopics the new top results cover, and refresh the publication or update date only when the content really changed.',
                'Restore internal links that may have been lost in navigation or template changes.',
            ],
            'keep' => ['Keep the URL; do not move the content to a new address while you recover the ranking.'],
            'measure' => ['Track the position of ' . $q . ' daily and the page’s clicks weekly. ' . self::MEASURE_WINDOW],
            'revert' => ['If a recent edit caused the drop, restore the version that ranked before and reapply the changes one at a time.'],
            'cautions' => ['Rankings fluctuate after core updates; avoid large rewrites in the first days of a volatile period.'],
            'also' => ['Expand the page into a better match for the current results.', 'Strengthen external links if competitors gained links.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function trafficDecline(string $p): array
    {
        return [
            'title' => 'Win back lost traffic to a page',
            'goal' => 'Identify which queries lost clicks for ' . $p . ' and fix the cause: rankings, snippet, freshness or demand.',
            'applies' => ['The page lost at least 30% of its clicks compared with the period before.'],
            'before' => [
                'Split the loss by query: lost positions, lower CTR at the same position, or lower demand (fewer impressions overall).',
                'Check seasonality against the same period last year before changing anything.',
                'Check the change timeline and indexability of ' . $p . '.',
            ],
            'change' => [
                'If positions fell: update outdated facts, expand thin sections and add sections that the current top results cover.',
                'If CTR fell at the same position: rewrite the title and description and check for new rich results or AI answers above you.',
                'If demand fell: refocus the page on related queries that are still growing, or merge it into a stronger page.',
            ],
            'keep' => ['Keep the URL and the sections that still bring clicks.'],
            'measure' => ['Track clicks and impressions of the page and its top queries. ' . self::MEASURE_WINDOW],
            'revert' => ['If clicks keep falling after the change, restore the previous version and test one fix at a time.'],
            'cautions' => ['A seasonal dip is not a problem to fix; compare year over year first.'],
            'also' => ['Add internal links from pages that gained traffic.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function contentGap(string $q): array
    {
        return [
            'title' => 'Create a page that answers an unserved search',
            'goal' => 'Publish or upgrade one page whose main purpose is to answer ' . $q . ' completely.',
            'applies' => ['Your site appears for ' . $q . ' only beyond page two, so no page answers it well yet.'],
            'before' => [
                'Check whether an existing page could be expanded to cover ' . $q . ' without diluting its main topic; prefer that over a new thin page.',
                'Study the top results for the format searchers expect (guide, list, comparison, product category, tool).',
            ],
            'change' => [
                'Create a dedicated page with ' . $q . ' in the URL slug, title and H1, written for that one intent.',
                'Answer the question in the first paragraph, then cover the subtopics, with specific facts, examples and a clear next step.',
                'Link to the new page from the most relevant existing pages and from the navigation or hub page of its topic.',
                'Add structured data that fits the page type (Article, Product, FAQ for AI answers, HowTo where valid).',
            ],
            'keep' => ['Do not create several near-identical pages for variants of the same query; one strong page per intent.'],
            'measure' => ['Track impressions, position and clicks for ' . $q . ' from the first week after indexing; expect results over two to three months.'],
            'revert' => ['If the page stays beyond page two after three months, merge it into the closest stronger page with a 301 redirect.'],
            'cautions' => ['Do not mass-produce generated pages for query variants; publish fewer, better pages.', SeoGuardrails::PAGES[4]],
            'also' => ['Build a topic hub that links related articles together.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function keywordGap(string $q): array
    {
        $plan = self::contentGap($q);
        $plan['title'] = 'Compete for a keyword your competitors already rank for';
        $plan['applies'] = ['Competitors rank on page one for ' . $q . ' and your site does not rank for it at all.'];
        array_unshift($plan['before'], 'Compare the competitor pages that rank for ' . $q . ': their format, depth, freshness and internal links. Aim to be clearly more useful, not a copy.');
        return $plan;
    }

    /** @return array<string, mixed> */
    private static function competitorGap(string $q, string $p): array
    {
        $plan = self::quickWin($q, $p);
        $plan['title'] = 'Close the gap to a competitor that outranks you';
        $plan['applies'] = ['A competitor ranks far ahead of ' . $p . ' for ' . $q . '.'];
        array_unshift($plan['before'], 'Compare your page with the competitor page side by side: coverage, structure, freshness, media, internal links and referring domains.');
        return $plan;
    }

    /** @return array<string, mixed> */
    private static function mergeDuplicates(string $keep, string $merge): array
    {
        return [
            'title' => 'Merge near-duplicate pages into one stronger page',
            'goal' => 'Replace two near-identical pages with one complete page: keep ' . $keep . ' and fold ' . $merge . ' into it.',
            'applies' => ['Two pages share most of their body text and at least one of them is shown in search.'],
            'before' => [
                'Open both pages and Research → Content inventory → Overlap review: confirm they serve the same purpose and audience.',
                'Note what only the other page has (sections, facts, images, reviews) and which sites link to it.',
                'If they must both stay (for example two sizes of one product), differentiate the text or set a canonical instead.',
            ],
            'change' => [
                'Move the unique, useful parts of ' . $merge . ' into ' . $keep . ' and rewrite them so the page reads as one.',
                'Redirect ' . $merge . ' to ' . $keep . ' with a permanent (301) redirect and update internal links and the sitemap.',
                'Record the decision as *Merge planned* in the overlap review, so the team sees it.',
            ],
            'keep' => ['Keep the address with the most clicks and links, and its title and main heading unless they are weak.'],
            'measure' => ['Track the combined clicks and impressions of both addresses on the kept page. ' . self::MEASURE_WINDOW],
            'revert' => ['If the kept page loses clicks the merged page used to get, restore the sections that carried them.'],
            'cautions' => ['Never delete the merged page without a redirect, and do not merge pages that answer different searches.'],
            'also' => ['Merge further copies of the same text into the same page, one group at a time.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function cannibalization(string $q): array
    {
        return [
            'title' => 'Give one page clear ownership of a query',
            'goal' => 'Stop several of your pages competing for ' . $q . ' by making one page the clear primary result.',
            'applies' => ['Two or more of your pages receive impressions for ' . $q . ' and none of them ranks consistently.'],
            'before' => [
                'Pick the primary page: the one with the best intent match, most links and most clicks.',
                'Decide for each other page: merge (same intent), differentiate (different intent) or keep as supporting content.',
            ],
            'change' => [
                'Merge pages with the same intent into the primary page, keeping their best sections, and 301-redirect the merged URLs to it.',
                'For product or filter duplicates that must stay, set the canonical to the primary URL.',
                'Re-focus pages with a different intent: change their titles, H1s and content so they target their own queries.',
                'Point internal links for ' . $q . ' to the primary page only, with consistent anchor text.',
            ],
            'keep' => ['Keep the primary page’s URL and its strongest content.'],
            'measure' => ['Track which URL ranks for ' . $q . ' and its position stability, plus combined clicks of the affected pages. ' . self::MEASURE_WINDOW],
            'revert' => ['If combined clicks fall after the merge, review which merged sections carried traffic and restore them on the primary page.'],
            'cautions' => ['Do not delete pages that have backlinks without redirecting them.'],
            'also' => ['Build a hub page that links to each differentiated page.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function internalLinks(string $p): array
    {
        return [
            'title' => 'Send more internal links to an important page',
            'goal' => 'Make ' . $p . ' easier for visitors and crawlers to reach and show search engines it matters.',
            'applies' => ['An important page has few internal links or sits deep in the site structure.'],
            'before' => ['List pages on the same topic that already get traffic or links, and the main queries ' . $p . ' should rank for.'],
            'change' => [
                'Add three to ten contextual links to ' . $p . ' from related pages, inside the body text, with descriptive anchors that name its topic.',
                'Link ' . $p . ' from its category, hub or pillar page, and link back from it to that hub.',
                'Make sure the page is reachable in three clicks or fewer from the homepage.',
                'Replace internal links that point to redirects or broken URLs with the final address.',
            ],
            'keep' => ['Keep the page’s content unchanged during the test so the effect of the links can be seen.'],
            'measure' => ['Track impressions, average position and clicks of ' . $p . '. ' . self::MEASURE_WINDOW],
            'revert' => ['Internal links rarely hurt; remove only links that confuse visitors or point to an off-topic page.'],
            'cautions' => ['Avoid sitewide footer links with exact-match anchors; contextual links work better.'],
            'also' => ['Add a related-content block on templates of the same topic.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function linkBuilding(string $p): array
    {
        return [
            'title' => 'Earn relevant links from other sites',
            'goal' => 'Win editorial links to ' . $p . ' from sites that already link to your competitors or cover your topic.',
            'applies' => ['You have clearly fewer referring domains than competitors, or a strong site links to competitors but not to you.'],
            'before' => [
                'List the referring domains your competitors share and you lack; keep those relevant to your topic and audience.',
                'Pick the page that deserves links: original data, a useful guide, a tool or a strong category page.',
            ],
            'change' => [
                'Create something worth citing on ' . $p . ': original numbers, a survey, a comparison, expert quotes or a free resource.',
                'Pitch it to the editors and authors on your list with a short, specific note about why it helps their readers.',
                'Offer guest articles only to relevant, real publications, with one natural contextual link to the target page.',
                'Ask partners, suppliers and associations that already mention you to link to the right page.',
            ],
            'keep' => ['Keep on-page changes small during the campaign so the effect of new links can be read.'],
            'measure' => ['Track new referring domains to the page and its positions over two to three months.'],
            'revert' => ['Stop any tactic that produces irrelevant or low-quality links.'],
            'cautions' => ['Do not buy links or use link networks; they risk penalties and rarely last.'],
            'also' => ['Reclaim unlinked brand mentions by asking the author to add a link.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function lostLink(string $p): array
    {
        return [
            'title' => 'Reclaim a lost link',
            'goal' => 'Get a valuable link back to ' . $p . ' or to its replacement.',
            'applies' => ['A strong site stopped linking to you.'],
            'before' => ['Open the linking page: was the link removed, the page deleted, or does it now point to a broken or redirected URL of yours?'],
            'change' => [
                'If your target URL changed or broke, 301-redirect it to the best current page.',
                'If the link was removed, contact the author with the current, improved resource and ask to restore it.',
                'If the linking page was deleted, find where its content moved and ask for the link there.',
            ],
            'keep' => ['Keep the target page’s URL stable from now on.'],
            'measure' => ['Check the referring domain again in four weeks.'],
            'revert' => [],
            'cautions' => [],
            'also' => ['Look for other links to the same lost URL and redirect it.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function spamLinks(): array
    {
        return [
            'title' => 'Review spammy links calmly',
            'goal' => 'Decide whether suspicious links need any action at all.',
            'applies' => ['Many links to your site look spammy.'],
            'before' => ['Check Search Console → Manual actions. Without a manual action, search engines usually ignore spam links on their own.'],
            'change' => [
                'Only if there is a manual action for unnatural links: remove what you can, document your outreach and disavow the remaining domains, then request a review.',
                'Without a manual action: monitor, and spend the effort on earning good links instead.',
            ],
            'keep' => [],
            'measure' => ['Check manual actions and rankings monthly.'],
            'revert' => ['A disavow file can be edited or removed if it was too broad.'],
            'cautions' => ['Do not disavow links preventively; a broad disavow can remove links that help you.', SeoGuardrails::PAGES[5]],
            'also' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function aiOverview(string $q, string $p): array
    {
        return [
            'title' => 'Get cited in AI answers for a query you rank for',
            'goal' => 'Make ' . $p . ' easy for AI answers to quote for ' . $q . '.',
            'applies' => ['An AI Overview for ' . $q . ' cites other sites, although you rank on page one.'],
            'before' => ['Read the AI answer and the cited pages: which facts, definitions or lists do they quote?'],
            'change' => [
                'Answer ' . $q . ' in one or two plain sentences directly under a heading that states the question.',
                'Add specific, verifiable facts: numbers, dates, named sources and definitions, and cite authoritative sources where you use them.',
                'Structure key information as short lists or a table, and use question-style subheadings for the main follow-up questions.',
                'Add matching structured data (Article, Product, FAQ) and a visible author with credentials.',
                'Replace repeated keywords with natural variations and related entities.',
            ],
            'keep' => ['Keep the parts of the page that earn your current ranking.'],
            'measure' => ['Track whether ' . $q . ' cites your site in the next AI Overview checks, and the page’s clicks.'],
            'revert' => ['If the ranking drops after restructuring, restore the earlier structure and keep only the added facts.'],
            'cautions' => ['Do not fill pages with generated text to chase AI answers; AI systems quote precise, sourced content.'],
            'also' => ['Publish original data others will cite.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function clickErosion(string $q, string $p): array
    {
        return [
            'title' => 'Win back clicks the results page took',
            'goal' => 'Give searchers of ' . $q . ' a reason to click through again, while ' . $p . ' keeps its ranking.',
            'applies' => ['The ranking and the demand for ' . $q . ' held, but the click-through rate fell clearly.'],
            'before' => [
                'Search ' . $q . ' in a private window on mobile and desktop and note what now sits above or around your result: an AI Overview, an answer box, People also ask, videos, shopping results or ads.',
                'Read what the AI Overview or answer box already says: the part of your page it repeats no longer earns a click on its own.',
                'Record the current title, description, clicks, impressions, CTR and average position for ' . $q . '.',
            ],
            'change' => [
                'Add what the results page cannot give: original data, worked examples, comparisons, a calculator or template, photos or first-hand experience, and say so early on the page.',
                'Rewrite the title and description to promise that extra value specifically, instead of the answer the results page already shows.',
                'Answer the basic question in one or two plain sentences near the top so AI answers can cite you, and link from there to the deeper sections.',
                'Add structured data that earns rich results where the page qualifies (Article, Product, Review, Breadcrumb, Video).',
            ],
            'keep' => ['Keep the URL and the sections that earn the ranking; this is about earning the click, not about ranking higher.'],
            'measure' => ['Track CTR and clicks for ' . $q . ' at a similar average position. ' . self::MEASURE_WINDOW, 'Check in the AI visibility report whether the AI Overview now cites your site.'],
            'revert' => ['If the position drops after the changes, restore the earlier version and keep only the snippet changes.'],
            'cautions' => ['Do not chase the lost clicks with clickbait titles that the page does not deliver; Google rewrites them and visitors leave.', ...SeoGuardrails::TITLES],
            'also' => ['If the query is a pure definition, put the effort into queries that need depth instead.', 'Consider a video or a tool if those formats appear on the results page.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function formatMismatch(string $q, string $p, ?string $format): array
    {
        $target = $format ?? 'the kind of page most top results are';

        return [
            'title' => 'Build the kind of page Google ranks for this search',
            'goal' => 'Compete for ' . $q . ' with ' . $target . ', instead of improving a page of the wrong kind.',
            'applies' => ['Most top results for ' . $q . ' are a different kind of page than ' . $p . '.'],
            'before' => [
                'Open the top results for ' . $q . ' and confirm the pattern: what do the pages offer (products to buy, a list to compare, a step-by-step answer, a working tool)?',
                'Check whether you already have a page of the right kind; if so, strengthen that page instead of creating a new one.',
            ],
            'change' => [
                'Create or adapt the target page for ' . $q . ' as ' . $target . ', matching what searchers get from the top results and going further.',
                'Link the new page from ' . $p . ' with descriptive anchor text, and from navigation or category pages where it belongs.',
                'Narrow ' . $p . ' to the intent it fits best, so the two pages do not compete for the same query.',
            ],
            'keep' => ['Keep ' . $p . ' online and its URL unchanged; it may rank for other queries.'],
            'measure' => ['Track which of your pages ranks for ' . $q . ' and its position. ' . self::MEASURE_WINDOW, 'Check for cannibalization: both pages should not swap positions for the same query.'],
            'revert' => ['If the new page does not rank after eight to twelve weeks and the old page lost visibility, point the old page back to its earlier focus.'],
            'cautions' => [SeoGuardrails::PAGES[3], 'Do not redirect the old page to the new one if it ranks for other valuable queries.'],
            'also' => ['If forum threads rank, a first-hand, detailed answer page can outrank them.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function toolIdea(string $q): array
    {
        return [
            'title' => 'Build a free tool searchers are looking for',
            'goal' => 'Answer ' . $q . ' with a working tool on your site that does the job faster or better than what ranks today.',
            'applies' => ['People search for a working tool (' . $q . '), with real demand, and you are not in the top three.'],
            'before' => [
                'Try the top-ranking tools for ' . $q . ': note their inputs, outputs, speed, limits, ads and what users must do to get a result.',
                'Write a short brief: the one job the tool does, its inputs and outputs, the formula or data it relies on, edge cases, and how you will check that results are correct. A content brief for this finding drafts it for you.',
                'Decide how the tool connects to your business: which page or offer it should lead to, and whether it needs a sign-up (usually it should not).',
            ],
            'change' => [
                'Build the tool so it works on mobile without a login, shows the result instantly and explains how it was calculated.',
                'Put the tool at the top of a page whose title and heading match ' . $q . ', with a short explanation, examples, a FAQ and the sources of any formula or data below it.',
                'Link the tool from related articles, category or product pages, and link from the tool to your relevant offer.',
                'Tell the people who would link to it: communities, partners, resource pages and newsletters in your niche.',
            ],
            'keep' => ['Keep the URL stable once the tool is published, and keep its results correct as rules, prices or rates change.'],
            'measure' => ['Track the position and clicks for ' . $q . ' and its variants, the number of tool uses (an analytics event) and new referring domains to the page. ' . self::MEASURE_WINDOW],
            'revert' => ['If the tool gets no traffic after twelve weeks, compare it with what ranks: a missing feature, a slow page or a weaker explanation is the usual reason.'],
            'cautions' => ['Do not hide the result behind an e-mail form; searchers leave and links do not come.', 'Do not publish a calculator whose formula you cannot source or verify; wrong results destroy trust.'],
            'also' => ['Offer an embed code so other sites can use the tool with a link back.', 'Add a printable or downloadable result.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function promptGap(string $prompt, string $rivals): array
    {
        return [
            'title' => 'Become an answer AI assistants give for this question',
            'goal' => 'Be named or cited in AI answers to “' . $prompt . '”, where they now recommend ' . $rivals . '.',
            'applies' => ['At least two AI assistants named or cited the same competitor for this question, and none mentioned you.'],
            'before' => [
                'Read the full answers and their cited sources under AI visibility → AI assistants: which pages and sites do the assistants rely on?',
                'Check whether you have a page that answers this question directly; AI assistants can only recommend what they can find and quote.',
            ],
            'change' => [
                'Publish or improve a page that answers the question directly: who it is for, what you offer, how you compare, prices or ranges and proof, in plain sentences an assistant can quote.',
                'Get included in the sources the answers cite: comparison and "best of" articles, directories, reviews and industry publications.',
                'Make your brand name, offer and category consistent on your site, profiles and listings, so assistants connect them.',
            ],
            'keep' => ['Keep factual pages stable and current; assistants quote facts that agree across sources.'],
            'measure' => ['Run the AI answer check again after four to eight weeks and compare which brands are named; single answers vary, so look for a pattern across platforms and runs.'],
            'cautions' => ['Do not fill your site with generated text to chase AI answers; assistants quote precise, sourced content.', 'Do not treat one answer as a trend: re-check before and after changes.'],
            'also' => ['Track the related keywords as well: pages that rank well are more often cited.'],
        ];
    }


    /** @return array<string, mixed> */
    private static function aiMentions(string $q): array
    {
        return [
            'title' => 'Become a brand AI assistants mention',
            'goal' => 'Increase how often AI assistants name your brand for ' . $q . ' and related questions.',
            'applies' => ['AI assistants mention competitors far more often than your brand.'],
            'before' => ['Ask the assistants the questions your buyers ask and note which sources and brands they name.'],
            'change' => [
                'Publish clear, factual pages about what you offer, for whom, prices, comparisons and alternatives, using consistent brand and product names.',
                'Get listed and reviewed on the directories, comparison sites and communities the assistants cite.',
                'Add Organization and Product structured data and keep your profiles (Google Business Profile, social, marketplaces) consistent.',
                'Earn mentions in relevant publications with original data or expert commentary.',
            ],
            'keep' => ['Keep brand and product names consistent everywhere.'],
            'measure' => ['Track brand mentions in the next AI visibility checks; expect changes over months, not weeks.'],
            'revert' => [],
            'cautions' => ['Do not plant fake reviews or forum posts; they damage trust and violate platform rules.'],
            'also' => ['Answer common questions about your category on your own site.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function aiCrawlers(string $crawlers): array
    {
        return [
            'title' => 'Let AI search crawlers fetch your pages',
            'goal' => 'Allow ' . $crawlers . ' in robots.txt, so their answers can show and cite your pages.',
            'applies' => ['robots.txt disallows search or answer crawlers on the home page or on top Search Console pages.'],
            'before' => [
                'Check whether the block is intended: some sites keep AI tools out on purpose.',
                'Find the matching lines in robots.txt: a group naming the crawler, or the "User-agent: *" rules it falls back to.',
            ],
            'change' => [
                'Add a group for each search crawler you want to allow, for example "User-agent: OAI-SearchBot" followed by "Allow: /".',
                'Remove or narrow Disallow lines that match whole sections people search for.',
                'Training crawlers (GPTBot, ClaudeBot, Google-Extended, CCBot …) are separate: you can keep blocking them.',
            ],
            'keep' => ['Keep private areas (account, checkout, admin) disallowed.'],
            'measure' => ['Run the crawler check again on the AI visibility page; then watch visits from AI assistants there over the next weeks.'],
            'revert' => ['Restore the previous robots.txt if crawl load becomes a problem.'],
            'cautions' => ['A firewall or CDN bot rule can still block these crawlers even when robots.txt allows them.'],
            'also' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function napConsistency(string $fields): array
    {
        return [
            'title' => 'Make your website match your Business Profile',
            'goal' => 'State the same business name, address and phone number on your website as in Google Business Profile.',
            'applies' => ['The latest site audit found ' . $fields . ' different from the profile, or not on the site.'],
            'before' => ['Decide which details are correct (signage, registration) and fix the profile first if it is the one that is wrong.'],
            'change' => [
                'Show the exact name, address and phone number on the contact page and in the footer, as text rather than an image.',
                'Add LocalBusiness structured data (the most specific type, such as Store or Dentist) with the same name, address and telephone, and the website and profile links in sameAs.',
                'Make the phone number a tel: link so people can call from a phone.',
                'Correct the same details in the main directories and maps that list the business.',
            ],
            'keep' => ['Keep one way of writing the address and phone number everywhere; do not add keywords to the business name.'],
            'measure' => ['Run the site audit again to confirm the details match, then follow local pack positions, calls and direction requests over 6–12 weeks.'],
            'revert' => [],
            'cautions' => ['Do not mark up an address the business does not serve customers from.'],
            'also' => ['A location page per real branch, each with its own details and markup.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function reviewReplies(): array
    {
        return [
            'title' => 'Answer your Google reviews',
            'goal' => 'Reply to every review, critical ones first, so searchers see an active, responsive business.',
            'applies' => ['Critical reviews have no reply, or most of the recent reviews are unanswered.'],
            'before' => ['Read the critical reviews and check what happened with each customer before replying.'],
            'change' => [
                'Reply to each critical review within a few days: thank the reviewer, address the specific problem, and offer a way to resolve it offline.',
                'Thank positive reviewers briefly and personally, mentioning the service they used.',
                'Set up notifications in Google Business Profile so new reviews get an answer within a week.',
                'Keep asking satisfied customers for reviews with a short link after each visit or order.',
            ],
            'keep' => ['Keep replies factual and polite; never share customer details.'],
            'measure' => ['Import the reviews again after 4–8 weeks and compare the reply share, rating and new reviews per month.'],
            'revert' => [],
            'cautions' => ['Do not offer rewards for reviews or ask only happy customers through a filter; Google removes such reviews.'],
            'also' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function localCategory(string $leading, string $own): array
    {
        return [
            'title' => 'Choose the Business Profile category the local pack uses',
            'goal' => 'Use the primary category that matches what people search for, where it describes your business.',
            'applies' => ['Most businesses in the local pack use ' . $leading . ', while your primary category is ' . $own . '.'],
            'before' => ['Check that ' . $leading . ' truly describes your main service; the category must describe the business, not a keyword.'],
            'change' => [
                'Make ' . $leading . ' the primary category in Google Business Profile if it is your main business, or add it as a secondary category.',
                'Add the services and products that belong to the category, and mention them in the profile description.',
                'Use the same wording on your website’s main service page.',
            ],
            'keep' => ['Keep categories you truly offer; remove ones that do not fit.'],
            'measure' => ['Follow local pack positions for your local keywords over 4–8 weeks after the change.'],
            'revert' => ['Change the primary category back if positions for your main services drop.'],
            'cautions' => ['Changing the primary category can trigger a new verification by Google.'],
            'also' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function localSeo(string $q, string $p): array
    {
        return [
            'title' => 'Improve local visibility',
            'goal' => 'Appear in the local pack and local results for ' . $q . '.',
            'applies' => ['You are missing from the local pack, or your Business Profile is unclaimed, incomplete or has fewer or weaker reviews than competitors.'],
            'before' => ['Compare your Business Profile with the three local pack results: category, reviews, photos, hours and services.'],
            'change' => [
                'Claim and verify the Business Profile, choose the most specific primary category and add services, hours, photos and a description.',
                'Keep name, address and phone identical on your site, the profile and the main directories.',
                'Ask every satisfied customer for a review with a short link right after the service, and reply to all reviews.',
                'Create or improve a location page on your site (' . $p . ') with the city in the title and H1, local details, a map and LocalBusiness structured data.',
            ],
            'keep' => ['Keep the business name exactly as on signage and documents; do not add keywords to it.'],
            'measure' => ['Track local pack positions for ' . $q . ', profile calls and direction requests over 6–12 weeks.'],
            'revert' => [],
            'cautions' => ['Do not create pages for cities you do not serve, or buy reviews.', SeoGuardrails::PAGES[1], SeoGuardrails::PAGES[6]],
            'also' => ['Add separate pages for each real location or service area.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function indexing(string $p): array
    {
        return [
            'title' => 'Get an important page indexed',
            'goal' => 'Make sure ' . $p . ' can be crawled, indexed and shown in search.',
            'applies' => ['Search Console reports the page as not indexed or excluded.'],
            'before' => ['Inspect the URL in Search Console and note the exact reason (noindex, canonical, crawled but not indexed, duplicate, soft 404).'],
            'change' => [
                'Remove noindex tags and robots.txt blocks that were set by mistake.',
                'Set the canonical of ' . $p . ' to itself unless it really is a duplicate.',
                'For “crawled but not indexed”: make the page clearly more useful than similar pages and link to it from relevant indexed pages.',
                'Include the page in the XML sitemap, then request indexing.',
            ],
            'keep' => ['Keep the URL stable while indexing is pending.'],
            'measure' => ['Re-inspect the URL after one to two weeks and track its impressions.'],
            'revert' => [],
            'cautions' => ['Do not request indexing repeatedly; fix the cause first.'],
            'also' => ['Remove or merge low-value duplicates so crawlers focus on important pages.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function canonical(string $p, string $chosen): array
    {
        return [
            'title' => 'Settle which URL Google should index',
            'goal' => 'Get Google to index ' . $p . ' itself, or deliberately consolidate it into ' . $chosen . '.',
            'applies' => ['Google’s inspection shows a Google-selected canonical that differs from the canonical the page declares.'],
            'before' => [
                'Compare ' . $p . ' with ' . $chosen . ': if their main content is nearly the same, Google treats them as duplicates.',
                'Check which of the two URLs internal links, the XML sitemap, hreflang and redirects point to.',
            ],
            'change' => [
                'If ' . $p . ' should rank: make its content clearly distinct, keep its canonical self-referencing, and point internal links and the sitemap to it.',
                'If ' . $chosen . ' is the better page: set the canonical of ' . $p . ' to it, or redirect ' . $p . ' there, and update internal links.',
                'Remove parameter or duplicate URL versions from the sitemap.',
            ],
            'keep' => ['Keep one consistent signal: canonical, internal links, sitemap and redirects should all name the same URL.'],
            'measure' => ['Re-inspect ' . $p . ' after two to four weeks and check that the Google-selected canonical matches your choice.'],
            'revert' => [],
            'cautions' => ['A canonical is a hint, not a command: conflicting links or near-duplicate content can keep Google’s choice.'],
            'also' => ['Merge thin variants into one stronger page where they target the same searches.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function richResults(string $p): array
    {
        return [
            'title' => 'Fix structured data errors',
            'goal' => 'Make the structured data on ' . $p . ' valid so it stays eligible for rich results.',
            'applies' => ['Google’s inspection reports errors in a detected rich result type.'],
            'before' => ['Run ' . $p . ' through Google’s Rich Results Test and note every error and warning per type.'],
            'change' => [
                'Add the missing required fields named in the errors, using values that are visible on the page.',
                'If the markup comes from a plugin or template, fix it there so every page of that type is corrected.',
                'Remove markup for content the page does not show.',
            ],
            'keep' => ['Keep the visible content and the markup in sync.'],
            'measure' => ['Validate again, request a recheck of ' . $p . ', then compare its CTR. ' . self::MEASURE_WINDOW],
            'revert' => [],
            'cautions' => ['Valid markup makes a page eligible; Google decides whether rich results are shown.'],
            'also' => ['Fix warnings for recommended fields once errors are gone.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function sitemap(string $sitemap): array
    {
        return [
            'title' => 'Fix a sitemap Google reports problems with',
            'goal' => 'Make ' . $sitemap . ' readable and list only URLs Google can index.',
            'applies' => ['Search Console reports errors or warnings for the sitemap.'],
            'before' => ['Open Search Console → Sitemaps → ' . $sitemap . ' and note each error and warning with its example URLs.'],
            'change' => [
                'Fix format errors: valid XML, absolute URLs on the property’s host, at most 50,000 URLs and 50 MB per file.',
                'Remove URLs that redirect, return 404, are blocked by robots.txt or carry noindex.',
                'Resubmit the sitemap after the fix.',
            ],
            'keep' => ['Keep the sitemap URL stable and listed in robots.txt.'],
            'measure' => ['Check after a few days that Search Console reads the sitemap without errors.'],
            'revert' => [],
            'cautions' => ['Generated sitemaps can reintroduce bad URLs: fix the generator, not only the file.'],
            'also' => ['Split large sites into several sitemaps by type so problems are easier to find.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function bingCrawl(): array
    {
        return [
            'title' => 'Restore crawling and indexing in Bing',
            'goal' => 'Bring Bing’s indexed pages back up and its crawl errors down.',
            'applies' => ['Bing’s crawl statistics show a drop in indexed pages or a high share of crawl errors.'],
            'before' => [
                'Open Bing Webmaster Tools → Site Explorer and Crawl information, and list the affected URLs and status codes.',
                'Compare the dates with recent changes on the site: migrations, redirects, robots.txt, noindex or server problems.',
            ],
            'change' => [
                'Fix server errors (5xx) and timeouts first; they stop crawling of otherwise good pages.',
                'Redirect removed pages that still have links, and fix internal links that lead to errors.',
                'Unblock pages Bing should index in robots.txt, and submit important URLs (IndexNow or Bing’s URL submission).',
            ],
            'keep' => ['Keep URLs stable while Bing recrawls the site.'],
            'measure' => ['Watch pages in Bing’s index and crawl errors on the Bing view over the next two to four weeks.'],
            'revert' => [],
            'cautions' => ['A planned removal of low-value pages also lowers the count; that is not a problem to fix.'],
            'also' => ['Bing’s index also feeds Yahoo, DuckDuckGo, Ecosia and the web search of ChatGPT and Copilot.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function surfaceDecline(string $surface): array
    {
        return [
            'title' => 'Win back lost ' . $surface . ' traffic',
            'goal' => 'Find out why clicks from ' . $surface . ' fell and fix the cause.',
            'applies' => ['Clicks from ' . $surface . ' fell by at least 30% compared with the period before.'],
            'before' => [
                'Open Search Console → Performance with the matching search type and find the pages that lost the most clicks.',
                'Compare publishing frequency, page changes and indexability with the previous period.',
            ],
            'change' => [
                'Check that the affected pages are still indexed and that their images or videos are crawlable.',
                'For Discover and News: publish timely, original content with large images and clear dates.',
                'For image and video search: keep media URLs stable and add descriptive alt text, structured data and sitemaps.',
            ],
            'keep' => ['Keep the pages and media that still bring clicks unchanged.'],
            'measure' => ['Track clicks from ' . $surface . ' on Performance → Other Google surfaces. ' . self::MEASURE_WINDOW],
            'revert' => [],
            'cautions' => ['Discover and News traffic is naturally volatile; a single strong period is not a baseline.'],
            'also' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function coreWebVitals(string $p): array
    {
        return [
            'title' => 'Make a page fast for real visitors',
            'goal' => 'Bring the mobile Core Web Vitals of ' . $p . ' into the good range.',
            'applies' => ['Real-user measurements show LCP, INP or CLS above the good threshold.'],
            'before' => ['Run a PageSpeed diagnostic for ' . $p . ' and note the largest element, long tasks and layout shifts.'],
            'change' => [
                'LCP: serve the main image in a modern format at the right size, preload it, avoid lazy-loading it and reduce server response time with caching.',
                'INP: defer or remove non-critical third-party scripts, break up long JavaScript tasks and delay chat or tracking widgets until after interaction.',
                'CLS: set width and height on images and embeds, reserve space for ads and banners, and avoid inserting content above existing content.',
                'Compress images and delay non-essential animations until after the first paint.',
            ],
            'keep' => ['Keep content and layout the same so the visitor experience does not change apart from speed.'],
            'measure' => ['Field data updates over 28 days: check the CrUX values again a month after the fix, and conversions on the page.'],
            'revert' => ['Revert any optimisation that breaks functionality or tracking.'],
            'cautions' => ['Lab scores are a guide; the real-user field values are what count.'],
            'also' => ['Apply the same fixes to the page template so all similar pages improve.'],
        ];
    }

    /** Issue-type specific steps for site audit findings. */
    private const array SITE_ISSUES = [
        'broken_page' => ['Fix broken pages', 'Restore the page, or 301-redirect it to the closest relevant live page, and update internal links that point to it.'],
        'server_error' => ['Fix server errors', 'Check server logs for the failing URLs, fix the cause (timeouts, memory, crashing plugins) and confirm a 200 response.'],
        'unreachable' => ['Make pages reachable', 'Check DNS, TLS and firewall rules for the failing URLs and confirm crawlers can fetch them.'],
        'fetch_refused' => ['Allow crawlers to fetch pages', 'Review security or bot-protection rules that block crawlers and allow verified search engine bots.'],
        'missing_title' => ['Add missing titles', 'Write a unique title for each page that names its main topic and benefit, under about 60 characters.'],
        'duplicate_title' => ['Make titles unique', 'Give each page a title that reflects what makes it different; for true duplicates, merge pages or set canonicals.'],
        'title_too_long' => ['Shorten long titles', 'Move the main topic to the start and cut titles to about 60 characters so the important words stay visible.'],
        'title_too_short' => ['Expand short titles', 'Add the specific topic, type or benefit so the title describes the page on its own.'],
        'missing_description' => ['Add meta descriptions', 'Write a unique description per page: a short answer plus a reason to click, about 140–155 characters.'],
        'duplicate_description' => ['Make descriptions unique', 'Rewrite repeated descriptions so each states what that page specifically offers.'],
        'description_too_long' => ['Shorten long descriptions', 'Keep the key message in the first 150 characters.'],
        'missing_h1' => ['Add a main heading', 'Add one visible H1 that states the page topic and matches the title’s promise.'],
        'multiple_h1' => ['Use one main heading', 'Keep a single H1 for the page topic and turn the others into H2 section headings.'],
        'redirect_chain' => ['Shorten redirect chains', 'Point every redirect straight to the final URL and update internal links to the final address.'],
        'redirect' => ['Link to final URLs', 'Update internal links, canonicals and sitemap entries so they point to the final URL instead of a redirect.'],
        'http_url' => ['Serve every page over HTTPS', 'Redirect HTTP to HTTPS with 301s and update internal links, canonicals and the sitemap.'],
        'mixed_content' => ['Remove mixed content', 'Load every image, script and stylesheet over HTTPS.'],
        'noindex' => ['Check noindex pages', 'Remove noindex from pages that should rank; keep it only for thin, private or utility pages.'],
        'canonical_elsewhere' => ['Check canonicals', 'Make pages that should rank canonical to themselves; point canonicals elsewhere only for real duplicates.'],
        'thin_content' => ['Strengthen thin pages', 'Add useful, specific content (details, examples, comparisons) or merge the page into a stronger related page.'],
        'blocked_by_robots' => ['Unblock important pages', 'Remove robots.txt rules that block pages or resources needed to render and rank them.'],
        'not_linked' => ['Link orphan pages', 'Link each orphan page from at least one relevant, indexed page and its category or hub; remove pages that are no longer needed.'],
        'slow_page' => ['Speed up slow pages', 'Enable caching, compress HTML and images, and reduce heavy scripts on the slowest templates.'],
        'large_page' => ['Reduce page weight', 'Compress and resize images, remove unused scripts and styles, and lazy-load below-the-fold media.'],
        'broken_asset' => ['Fix broken resources', 'Replace or remove images, scripts and styles that return errors.'],
        'broken_external_link' => ['Fix broken outbound links', 'Update outbound links to the current address or remove them.'],
        'missing_image_alt' => ['Describe images', 'Add short, descriptive alt text to meaningful images; use alt="" for purely decorative images.'],
        'resource_unavailable' => ['Fix unavailable resources', 'Make sure resources needed to render the page load for crawlers and visitors.'],
        'deep_page' => ['Bring deep pages closer to the start page', 'Link each deep page from its category or hub page and from related content so it is three clicks or fewer from the start page.'],
        'missing_structured_data' => ['Add structured data', 'Add JSON-LD that matches the page type: Product with price, availability and reviews for products; Article with author and dates for articles; BreadcrumbList on all pages; LocalBusiness on location pages. Validate it with the Rich Results Test.'],
        'missing_sources' => ['Cite the sources of facts and figures', 'Link each statistic, study or claim in the text to its original source (inline, next to the fact), and prefer primary and authoritative sources over aggregators.'],
        'missing_content_markup' => ['Mark up what each page is', 'Keep the site-wide markup (breadcrumbs, organization) and add the page’s own type: Product with price, availability and reviews on product pages; Article or BlogPosting with author, datePublished and dateModified on articles. Generate it from the same data the page shows, and validate it with the Rich Results Test.'],
        'missing_questions' => ['Answer buyers’ questions on product and category pages', 'Add a short section near the top of the content with the three to five questions buyers ask before choosing (sizes, differences between options, delivery, use), each answered directly in one or two sentences. Use real questions from searches, reviews and customer service. FAQ markup may describe the section, but Google no longer shows FAQ rich results, so judge it by clicks and conversions.'],
        'missing_business_details' => ['State who runs the site', 'On the home page add Organization structured data (or the most specific LocalBusiness type if customers visit you) with the business name, logo, website, phone number and address, and the links to your social profiles in sameAs. Show the phone number as a tel: link and the address as text on the contact page, written the same as in your Google Business Profile and directories.'],
        'missing_spec_table' => ['Put product facts in a table', 'Collect the facts shoppers compare (size, material, compatibility, contents, flowering time …) in a table or a list of terms on each product page, generated from the product data so it stays consistent, and keep the selling text for what the table cannot say.'],
        'missing_author' => ['Show who wrote each article', 'Add a visible byline linking to an author page with a short bio and credentials, and add the author to the Article markup.'],
    ];

    /** @return array<string, mixed> */
    private static function siteIssue(string $type): array
    {
        [$title, $fix] = self::SITE_ISSUES[$type];
        $plan = self::technical();
        $plan['title'] = $title;
        $plan['goal'] = $fix;
        $plan['change'] = [$fix, 'Fix the cause in the page template or CMS setting where possible, so new pages do not repeat the problem.', 'Start with the affected pages that get the most clicks or links.'];
        return $plan;
    }

    /** @return array<string, mixed> */
    private static function technical(): array
    {
        return [
            'title' => 'Fix technical issues found by the audit',
            'goal' => 'Resolve the reported issue on the affected pages, starting with the ones that matter most.',
            'applies' => ['The latest site audit reported this issue on one or more pages.'],
            'before' => ['Open the example pages in the audit and confirm the issue in the browser or page source.', 'Sort affected pages by clicks and links so the most valuable ones are fixed first.'],
            'change' => ['Fix the issue on the affected pages and in the template that produces it.'],
            'keep' => ['Change only what the fix needs; avoid redesigns while fixing technical issues.', SeoGuardrails::PAGES[0]],
            'measure' => ['Run the site audit again after the fix and confirm the issue count fell; track the affected pages’ clicks.'],
            'revert' => ['Revert a fix that breaks pages, tracking or rendering.'],
            'cautions' => [],
            'also' => ['Finishing audit items together across a site tends to pay off more than fixing pages one by one.'],
        ];
    }
}
