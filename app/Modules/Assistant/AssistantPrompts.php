<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

/**
 * The prompt library in the chat panel: ready questions grouped by topic.
 * Each one is phrased so the assistant can answer it from the stored data
 * with the existing tools (filters, thresholds, periods and the output
 * format are spelled out). Selecting one puts the text in the input, so the
 * user can adjust dates or thresholds before sending.
 */
final class AssistantPrompts
{
    /**
     * @return array<string, array{label: string, prompts: list<array{title: string, text: string}>}>
     */
    public static function all(): array
    {
        return [
            'performance' => ['label' => 'Performance', 'prompts' => [
                ['title' => 'Last 28 days vs the 28 days before', 'text' => 'Compare clicks, impressions, CTR and average position for the last 28 days with the 28 days before. Show a table with both periods and the change, then explain the three biggest drivers using the queries and pages that gained or lost the most clicks.'],
                ['title' => 'Why did traffic change?', 'text' => 'Why did my organic traffic change in the last 28 days? Break the change down into queries and pages (sort by clicks change), check recorded changes in the change timeline for the same dates, and separate measured facts from possible explanations.'],
                ['title' => 'Daily clicks trend', 'text' => 'Show my daily Google clicks and impressions for the last 90 days as a line chart, and point out days or weeks that stand out from the trend.'],
                ['title' => 'Biggest losing queries', 'text' => 'Which 15 queries lost the most clicks in the last 28 days compared with the 28 days before? Show a table with clicks before and after, impressions change and position change, and say for each whether rankings, demand or CTR explains the loss.'],
                ['title' => 'Biggest losing pages', 'text' => 'Which 15 pages lost the most clicks in the last 28 days compared with the previous 28 days? Show clicks, impressions and average position before and after, and note recorded changes for those URLs.'],
                ['title' => 'Biggest winners', 'text' => 'Which queries and pages gained the most clicks in the last 28 days compared with the previous 28 days? Show the top 10 of each in tables and what they have in common.'],
                ['title' => 'Monthly SEO summary', 'text' => 'Write a monthly SEO summary for this project: search totals vs the previous month, top movers, ranking changes, new and resolved opportunities and recorded changes. Use headings and tables, keep it suitable to send to a client.'],
            ]],
            'ctr' => ['label' => 'CTR & snippets', 'prompts' => [
                ['title' => 'High impressions, low CTR (28 days)', 'text' => 'Show me queries with at least 500 impressions and a CTR below 1 % in the last 28 days, sorted by impressions. Table: query, impressions, clicks, CTR, average position. Then group them by likely cause (position too low, weak title/snippet, SERP features or intent mismatch).'],
                ['title' => 'Low CTR on page one', 'text' => 'Find queries in positions 1 to 5 over the last 28 days with at least 200 impressions and a CTR below 3 %. For the pages ranking for them, give the current title and meta description and a rewritten version for each.'],
                ['title' => 'Pages with weak CTR', 'text' => 'Which pages had at least 1,000 impressions and a CTR under 1.5 % in the last 28 days? Sort by impressions and suggest a new title and meta description for the top 10, ready to paste.'],
                ['title' => 'CTR drops without ranking loss', 'text' => 'Compare the last 28 days with the previous 28 days: which queries lost more than 1 percentage point of CTR while their average position stayed within one position? Explain what could have changed on the results page.'],
                ['title' => 'Title rewrites as a fix plan', 'text' => 'For the 20 pages with the most impressions and a CTR below the site average in the last 28 days, write new titles and meta descriptions and save them as a fix plan I can download.'],
            ]],
            'queries' => ['label' => 'Queries & intent', 'prompts' => [
                ['title' => 'Question queries ("how to", "what is")', 'text' => 'Show me queries containing "how to", "what is", "why", "which" or "can" from the last 28 days, sorted by impressions. Table with clicks, impressions, CTR and position, and suggest which questions deserve their own section or FAQ.'],
                ['title' => 'Striking-distance keywords', 'text' => 'List queries with an average position between 8 and 20 and at least 100 impressions in the last 28 days, sorted by impressions. These are close to page one: name the ranking page for the top 10 and what to improve on it.'],
                ['title' => 'Non-brand performance', 'text' => 'Show my non-brand queries for the last 28 days (exclude queries containing the brand name or domain), with totals for clicks and impressions compared with the brand queries, and the top 20 non-brand queries.'],
                ['title' => 'Commercial-intent queries', 'text' => 'Find queries containing "best", "price", "cost", "buy", "review", "vs" or "cheap" in the last 28 days. Show clicks, impressions, CTR and position and say which have the most revenue potential.'],
                ['title' => 'New queries this month', 'text' => 'Which queries had impressions in the last 28 days but none in the previous 28 days? Sort by impressions and say which topics they point to.'],
                ['title' => 'Long-tail queries', 'text' => 'Show queries with five or more words from the last 90 days that have at least 50 impressions, sorted by impressions, and group them into topics I could cover.'],
            ]],
            'pages' => ['label' => 'Pages & content', 'prompts' => [
                ['title' => 'Top pages and what they rank for', 'text' => 'Show my 10 pages with the most clicks in the last 28 days, and for the top 3 the main queries each one ranks for, with clicks, impressions and position.'],
                ['title' => 'Pages losing visibility', 'text' => 'Which pages lost the most impressions in the last 28 days compared with the previous 28 days? Show the change in impressions, clicks and position, and check whether page changes or site audit issues were recorded for them.'],
                ['title' => 'Blog section performance', 'text' => 'How did pages with "/blog/" in the URL perform in the last 28 days compared with the previous 28 days? Show the top 15 by clicks with the change, and the totals for the section.'],
                ['title' => 'Cannibalization check', 'text' => 'Look for queries where several of my pages compete (cannibalization) using the opportunities and page details. For each case, say which page should be the main one and what to do with the others.'],
                ['title' => 'Content brief ideas', 'text' => 'Based on queries with many impressions where my pages rank below position 10, suggest five content briefs: target keyword, search intent, suggested title and the questions the page must answer.'],
                ['title' => 'Content briefs in progress', 'text' => 'List my content briefs that are in draft or in progress, with the target keyword and current evidence, and which one I should finish first.'],
            ]],
            'markets' => ['label' => 'Countries & devices', 'prompts' => [
                ['title' => 'US CTR last week', 'text' => 'What was my CTR in the United States last week compared with the week before? Show clicks, impressions, CTR and position for both weeks and the share of total clicks.'],
                ['title' => 'Top countries', 'text' => 'Show my top 10 countries by clicks for the last 28 days with impressions, CTR, average position, share of clicks and the change against the previous 28 days.'],
                ['title' => 'Mobile vs desktop', 'text' => 'Compare mobile, desktop and tablet for the last 28 days: clicks, impressions, CTR and average position, with the change against the previous 28 days. Where is mobile clearly weaker?'],
                ['title' => 'Markets with low CTR', 'text' => 'Which countries had at least 1,000 impressions in the last 28 days but a CTR below my site average? Show them in a table and suggest whether this points to language, relevance or ranking issues.'],
                ['title' => 'Core Web Vitals by device', 'text' => 'Summarize my Core Web Vitals field data for mobile and desktop: which URLs or the origin fail LCP, INP or CLS, and how far from the "good" thresholds they are.'],
            ]],
            'rankings' => ['label' => 'Rankings & competitors', 'prompts' => [
                ['title' => 'Ranking winners and losers', 'text' => 'Summarize the latest rank check: visibility, average position, top 3 and top 10 counts compared with a week earlier, and tables of the biggest winners and losers.'],
                ['title' => 'Keywords about to reach top 3', 'text' => 'Which tracked keywords rank in positions 4 to 10 with the highest search volume? Show position, volume and the ranking URL, and what would most likely move each into the top 3.'],
                ['title' => 'Competitor comparison', 'text' => 'Compare my visibility, average position and top 10 count with each competitor on the latest rank check. Which competitor is gaining, and on which keywords do they beat me?'],
                ['title' => 'Keyword gap', 'text' => 'Show keywords my main competitor ranks for that I do not, from the stored keyword research. Sort by search volume, include difficulty and intent, and mark the ones that fit my business.'],
                ['title' => 'AI Overview visibility', 'text' => 'How often do AI Overviews appear on my tracked keywords, how often am I cited compared with my competitors, and which keywords cite competitors but not me?'],
                ['title' => 'Local rankings', 'text' => 'Summarize my local SEO: Google Maps positions per local keyword with the change since the previous check, the top 3 competitors per keyword and my rating and review count compared with them.'],
            ]],
            'technical' => ['label' => 'Technical & indexing', 'prompts' => [
                ['title' => 'Site audit priorities', 'text' => 'Summarize the latest site audit: health score, issues by severity and number of pages, and the five fixes with the biggest effect, in the order I should do them.'],
                ['title' => 'Fix titles and descriptions', 'text' => 'List pages with missing, duplicate or too long titles and meta descriptions from the site audit, with the current values and a suggested replacement for each, and save them as a fix plan.'],
                ['title' => 'Broken pages and redirects', 'text' => 'Which pages return errors or redirect chains in the latest site audit? Give each URL, the status, the redirect target and the exact fix.'],
                ['title' => 'Significant page changes', 'text' => 'Show significant changes detected on my watched pages (status code, canonical or noindex) in the last 30 days, with before and after values, and whether search performance of those URLs changed afterwards.'],
                ['title' => 'Indexing check for a URL', 'text' => 'What is the saved Google indexing status of https://example.com/page? Explain the verdict, last crawl and selected canonical, and what to check next.'],
                ['title' => 'Backlink changes', 'text' => 'Which referring domains did I gain and lose in the last 30 days? Show domain rank and spam score, and list domains that link to two or more competitors but not to me.'],
            ]],
            'planning' => ['label' => 'Plan & report', 'prompts' => [
                ['title' => 'What to do this week', 'text' => 'What should I work on first this week? Use the open opportunities, pick the five with the best impact for the effort, and give for each the reason, the evidence and the exact next step.'],
                ['title' => 'Quick wins', 'text' => 'List open opportunities with low effort and high impact, with the evidence behind each and the pages involved, as a checklist.'],
                ['title' => 'Impact of a recorded change', 'text' => 'Review the impact of my most recent recorded change in the change timeline: compare the periods before and after, show the coverage, and separate facts from hypotheses.'],
                ['title' => '28-day forecast', 'text' => 'What do the forecasts expect for clicks and impressions over the next 28 days, how reliable have previous forecasts been, and which series cannot be forecast and why?'],
                ['title' => 'Team task status', 'text' => 'Show the SEO team tasks that are overdue or due this week, who they are assigned to and which opportunities they belong to.'],
                ['title' => 'Organic conversions (GA4)', 'text' => 'How many organic sessions, conversions and how much revenue did GA4 record in the last 28 days compared with the previous 28 days, and which landing pages contributed the most?'],
            ]],
        ];
    }
}
