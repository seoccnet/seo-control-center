<?php

declare(strict_types=1);

/**
 * Research → Article ideas (ADR-041, G1).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<SeoControlCenter\Domain\ArticleIdeas\ArticleIdea> $ideas
 * @var ?string $built_at
 * @var ?SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRun $run
 * @var list<array{domain: string, found: bool}> $competitors
 * @var float $estimated_cost
 * @var float $lookup_cost price of one lookup: one competitor in one market
 * @var string $market
 * @var list<array{key: string, label: string, cost: float}> $markets the markets a lookup covers
 * @var string $market_source profile, keywords or default
 * @var list<array{market: string, label: string, reason: string}> $skipped_markets
 * @var list<array{key: string, label: string}> $idea_markets markets the listed ideas come from
 * @var ?string $market_filter
 * @var bool $can_edit
 * @var bool $featureOn
 * @var bool $available
 * @var bool $rebuildable
 * @var array<string, SeoControlCenter\Domain\ArticleIdeas\ArticleOutline> $outlines
 * @var string $outline_fresh_since
 * @var array<string, string> $plans idea key => topic plan public ID
 * @var list<SeoControlCenter\Domain\ArticleIdeas\ArticleTracking> $published
 * @var string $today
 */

use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleOutline;

$base = '/projects/' . $project->publicId . '/article-ideas';
$view->extend('layouts/app', ['title' => 'Article ideas · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'article-ideas']);
$path = static function (string $url): string {
    $parts = parse_url($url);

    return is_array($parts) ? ((string) ($parts['path'] ?? '/')) . (isset($parts['query']) ? '?' . $parts['query'] : '') : $url;
};
$levels = ['low' => 'Low', 'medium' => 'Medium', 'hard' => 'Hard'];
$briefs = '/projects/' . $project->publicId . '/content-briefs/new';
$profile = '/projects/' . $project->publicId . '/opportunities/business';
$multi = count($markets) > 1 || count($idea_markets) > 1;
?>
<div class="research-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Article ideas</p><h2>Article ideas</h2><p class="muted">Articles to write or update next, from the searches your competitors' articles rank for and how your own pages do. Each idea shows the likely traffic, how hard the competition is, links that would speed it up and a rough time to rank. These are estimates with ranges, each with the basis it was made on.</p></div></header>

<section class="card" aria-labelledby="ideas-run-heading">
    <h2 id="ideas-run-heading">Competitor articles</h2>
    <?php if ($competitors === []): ?>
        <p class="muted">No competitors yet. Add them under <a href="<?= $view->e('/projects/' . $project->publicId . '/competitors') ?>">Competitors</a>, or track keywords so SEOcc can find the sites that rank for them.</p>
    <?php else: ?>
        <p class="muted small">Compared with: <?php foreach ($competitors as $i => $competitor): ?><?= $i > 0 ? ', ' : '' ?><?= $view->e($competitor['domain']) ?><?= $competitor['found'] ? ' <span class="muted">(found in your results pages)</span>' : '' ?><?php endforeach; ?>. <?= count($markets) > 1 ? 'Markets' : 'Market' ?>: <?= $view->e($market !== '' ? $market : 'none DataForSEO Labs covers') ?>.</p>
    <?php endif; ?>
    <p class="muted small article-ideas-market-source"><?= match ($market_source) {
        'profile' => 'Markets from your business profile.',
        'keywords' => 'No markets in your business profile, so the markets of your tracked keywords are used, most keywords first.',
        default => 'No markets in your business profile and no tracked keywords, so the default market is used.',
    } ?> <a href="<?= $view->e($profile) ?>">Change the markets in the business profile</a>.</p>
    <?php if ($skipped_markets !== []): ?>
    <p class="small article-ideas-skipped" role="note">Skipped, because DataForSEO Labs has no competitor rankings for them: <?php foreach ($skipped_markets as $i => $skip): ?><?= $i > 0 ? '; ' : '' ?><?= $view->e($skip['label']) ?><?php endforeach; ?>. They are not looked up or paid for.</p>
    <?php endif; ?>
    <?php if ($run !== null): ?>
        <p class="small"><?= match ($run->status) {
            'queued' => 'Lookup waiting for cron (started ' . $view->e(substr($run->createdAt, 0, 16)) . ' UTC)…',
            'running' => 'Looking up competitor articles…',
            'failed' => 'The last lookup failed: ' . $view->e((string) $run->error),
            default => 'Last lookup ' . $view->e(substr((string) ($run->finishedAt ?? $run->createdAt), 0, 10)) . '.',
        } ?><?= $built_at !== null ? ' Ideas rebuilt ' . $view->e(substr($built_at, 0, 16)) . ' UTC (daily, free, from the stored lookup).' : '' ?></p>
    <?php endif; ?>
    <?php if ($can_edit && $featureOn && !$available): ?><p class="muted small">Connect DataForSEO under <a href="/integrations">Integrations</a> to look up competitor articles.</p>
    <?php elseif ($can_edit && $featureOn && $competitors !== [] && $markets !== [] && ($run === null || !$run->active())): ?>
    <?php if (count($markets) > 1): ?>
    <p class="small article-ideas-cost">Cost: <?= count($competitors) ?> competitor<?= count($competitors) === 1 ? '' : 's' ?> × <?= count($markets) ?> markets × about $<?= $view->e(number_format($lookup_cost, 2)) ?> per lookup = about $<?= $view->e(number_format($estimated_cost, 2)) ?> in total. Per market: <?php foreach ($markets as $i => $m): ?><?= $i > 0 ? ' · ' : '' ?><?= $view->e($m['label']) ?> $<?= $view->e(number_format($m['cost'], 2)) ?><?php endforeach; ?>.</p>
    <?php endif; ?>
    <form method="post" action="<?= $view->e($base) ?>" class="inline-form"><?= $view->csrfField() ?>
        <input type="hidden" name="confirmed_cost" value="<?= $view->e(number_format($estimated_cost, 2, '.', '')) ?>">
        <label class="small"><input type="checkbox" name="confirm_paid" value="1" required> Pay about $<?= $view->e(number_format($estimated_cost, 2)) ?> to DataForSEO for these competitors' rankings<?= count($markets) > 1 ? ' in ' . count($markets) . ' markets' : '' ?></label>
        <?php if ($run !== null && $run->status === 'done'): ?><label class="small"><input type="checkbox" name="confirm_repeat" value="1"> Run again and pay (last lookup <?= $view->e(substr((string) ($run->finishedAt ?? $run->createdAt), 0, 10)) ?>)</label><?php endif; ?>
        <button class="button button-primary"><?= $run === null ? 'Find article ideas' : 'Look up again' ?></button>
    </form>
    <?php elseif (!$featureOn): ?><p class="muted small">Article ideas need keyword data, which is turned off on this installation.</p><?php endif; ?>
    <?php if ($can_edit && $featureOn && $rebuildable && ($run === null || !$run->active())): ?>
    <form method="post" action="<?= $view->e($base . '/rebuild') ?>" class="inline-form"><?= $view->csrfField() ?>
        <button class="button button-ghost button-small">Rebuild ideas now</button>
        <span class="muted small">Free: uses the stored lookup<?= $multi ? ' of every market' : '' ?> with your current rankings and relevance rules.</span>
    </form>
    <?php endif; ?>
</section>

<?php if ($published !== []): ?>
<?php $calibrated = SeoControlCenter\Domain\ArticleIdeas\ArticleTracking::calibration($published); ?>
<section class="card" id="published" aria-labelledby="published-heading">
    <h2 id="published-heading">Published articles</h2>
    <p class="muted small">Articles from ideas, compared with what was predicted when they were published. Measured every day from your Search Console data for the article's address and its searches.</p>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls><thead><tr><th scope="col">Article</th><th scope="col">Published</th><th scope="col" class="num">Predicted clicks/month</th><th scope="col" class="num">Clicks/month now</th><th scope="col">Predicted time to rank</th><th scope="col">Top 10 for the main search</th><th scope="col" class="num">Best position</th><?php if ($can_edit): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($published as $t): ?>
        <?php $months = $t->monthsToTop10(); ?>
        <tr>
            <td><?= $view->e($t->mainKeyword) ?><br><a class="small" href="<?= $view->e($t->url) ?>" rel="noopener" target="_blank"><?= $view->e($path($t->url)) ?></a></td>
            <td><?= $view->e($t->publishedOn) ?><?= $t->source === SeoControlCenter\Domain\ArticleIdeas\ArticleTracking::BRIEF ? ' <span class="muted small">(from brief)</span>' : '' ?></td>
            <td class="num"><?= $view->e(number_format($t->prediction['traffic']['low']) . '–' . number_format($t->prediction['traffic']['high'])) ?></td>
            <td class="num"><?= $t->monthlyClicks() === null ? '<span class="muted">not checked yet</span>' : $view->e(number_format($t->monthlyClicks())) ?></td>
            <td><?= $view->e($t->prediction['months']['low'] . '–' . $t->prediction['months']['high']) ?> months</td>
            <td><?= $t->result === null ? '<span class="muted">not checked yet</span>' : ($months !== null ? 'after ' . $view->e(number_format($months, 1)) . ' months' : '<span class="muted">not yet, ' . $view->e(number_format($t->monthsSince($today), 1)) . ' months since publishing</span>') ?></td>
            <td class="num"><?= $view->e($t->result['best_position'] ?? '—') ?></td>
            <?php if ($can_edit): ?><td><form method="post" action="<?= $view->e($base . '/published/' . $t->publicId . '/stop') ?>"><?= $view->csrfField() ?><button class="button button-small button-ghost">Stop tracking</button></form></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($calibrated !== []): ?>
    <p class="small">Time-to-rank estimates now use your own results for: <?php foreach (array_keys($calibrated) as $i => $group): ?><?= $i > 0 ? ' · ' : '' ?><?= $view->e(str_replace(['new:', 'update:'], ['new articles, ', 'updates, '], $group)) ?> difficulty (<?= (int) $calibrated[$group]['count'] ?> articles, <?= (int) $calibrated[$group]['low'] ?>–<?= (int) $calibrated[$group]['high'] ?> months)<?php endforeach; ?>.</p>
    <?php else: ?>
    <p class="muted small">Once 3 tracked articles of one kind and difficulty reach the top 10, new estimates for them use your site's own time instead of the assumption.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($ideas === [] && $run !== null && $run->status === 'done'): ?>
<section class="card"><p class="muted">No article ideas: either you already rank in the top 3 for the topics your competitors' articles win, their ranking pages are not articles, or your business profile rules the searches out.</p></section>
<?php endif; ?>

<?php if (count($idea_markets) > 1): ?>
<form method="get" action="<?= $view->e($base) ?>" class="inline-form article-ideas-filter">
    <label class="small" for="ideas-market">Market</label>
    <select id="ideas-market" name="market">
        <option value="">All markets</option>
        <?php foreach ($idea_markets as $m): ?><option value="<?= $view->e($m['key']) ?>"<?= $market_filter === $m['key'] ? ' selected' : '' ?>><?= $view->e($m['label']) ?></option><?php endforeach; ?>
    </select>
    <button class="button button-small button-ghost">Show</button>
</form>
<?php endif; ?>

<?php foreach ($ideas as $rank => $idea): ?>
<?php $p = $idea->prediction; $split = count($idea->markets) > 1; ?>
<section class="card article-idea" aria-labelledby="idea-<?= $view->e(substr($idea->key, 0, 12)) ?>">
    <div class="sync-row"><div><p class="portfolio-eyebrow"><?= $idea->kind === ArticleIdea::UPDATE ? 'Update your article' : 'New article' ?> · #<?= $rank + 1 ?><?= $multi && $idea->markets !== [] ? ' · ' . $view->e($idea->marketLabel()) : '' ?></p>
        <h2 id="idea-<?= $view->e(substr($idea->key, 0, 12)) ?>"><?= $view->e(mb_strtoupper(mb_substr($idea->mainKeyword, 0, 1)) . mb_substr($idea->mainKeyword, 1)) ?></h2>
        <p class="muted small"><?= count($idea->keywords) ?> search<?= count($idea->keywords) === 1 ? '' : 'es' ?>, <?= $view->e(number_format($idea->volume)) ?> a month in total<?= $split ? ' across ' . count($idea->markets) . ' markets' : '' ?>. <?php foreach ($idea->reasons as $reason): ?><?= $view->e($reason) ?> <?php endforeach; ?></p></div>
        <span class="indexing-badge">~<?= $view->e(number_format($p['traffic']['expected'])) ?> clicks/month</span></div>
    <?php $existing = $inventory[$idea->key] ?? []; $written = array_values(array_filter($existing, static fn(array $r): bool => $r['article'])); $other = array_values(array_filter($existing, static fn(array $r): bool => !$r['article'])); ?>
    <?php if ($written !== []): ?>
    <div class="notice article-idea-inventory" role="note"><strong>Already in your content inventory.</strong> No page ranks for these searches yet, but this may not be missing:
        <ul><?php foreach ($written as $row): ?><li><?= $view->e($row['state_label']) ?>: <?= $row['url'] !== null ? '<a href="' . $view->e($row['url']) . '" rel="noopener noreferrer" target="_blank">' . $view->e($row['title'] ?? $row['url']) . '</a>' : $view->e((string) $row['title']) ?></li><?php endforeach; ?></ul>
        Improve or publish it before writing a second article. <a href="<?= $view->e('/projects/' . $project->publicId . '/inventory?q=' . rawurlencode($idea->mainKeyword)) ?>">Open the inventory</a></div>
    <?php endif; ?>
    <?php if ($other !== []): ?>
    <p class="muted small article-idea-other-pages">Other pages on this topic, not articles: <?php foreach ($other as $i => $row): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= $view->e((string) $row['url']) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e($row['title'] ?? (string) $row['url']) ?></a><?php endforeach; ?>. Link them to the new article.</p>
    <?php endif; ?>
    <?php if ($split): ?>
    <div class="table-scroll"><table class="table table-dense article-idea-markets" data-no-table-controls><caption class="visually-hidden">Per market</caption><thead><tr><th scope="col">Market</th><th scope="col" class="num">Searches a month</th><th scope="col" class="num">Clicks/month (positions 9 to 2)</th><th scope="col">Difficulty</th><th scope="col" class="num">Competitor articles</th></tr></thead><tbody>
    <?php foreach ($idea->markets as $m): ?><tr><td><?= $view->e(ArticleIdea::label($m['market'])) ?></td><td class="num"><?= $view->e(number_format($m['volume'])) ?></td><td class="num"><?= $view->e(number_format($m['traffic']['low']) . '–' . number_format($m['traffic']['high'])) ?></td><td><?= $view->e($levels[$m['difficulty']] ?? $m['difficulty']) ?></td><td class="num"><?= (int) $m['competitors'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <div class="article-idea-estimates">
        <div><h3>Traffic</h3><p><strong><?= $view->e(number_format($p['traffic']['low'])) ?>–<?= $view->e(number_format($p['traffic']['high'])) ?></strong> clicks/month</p><p class="muted small">At positions 9 to 2; about <?= $view->e(number_format($p['traffic']['expected'])) ?> at position 5. Volume × your site's click-through by position<?= $split ? ', added up over the markets above' : '' ?>.</p></div>
        <div><h3>Difficulty</h3><p><strong><?= $view->e($levels[$p['difficulty']['level']] ?? $p['difficulty']['level']) ?></strong></p><p class="muted small"><?= $view->e(implode(' ', $p['difficulty']['reasons'])) ?></p></div>
        <div><h3>Backlinks to speed it up</h3><?php if ($p['backlinks'] === null): ?><p class="muted small">Unknown: no referring-domain data for these results.</p><?php else: ?><p><strong><?= $p['backlinks']['high'] === 0 ? 'None needed' : $view->e($p['backlinks']['low'] . '–' . $p['backlinks']['high']) . ' referring domains' ?></strong></p><p class="muted small"><?= $view->e($p['backlinks']['basis']) ?></p><?php endif; ?></div>
        <div><h3>Time to rank</h3><p><strong><?= $view->e($p['months']['low'] . '–' . $p['months']['high']) ?> months</strong></p><p class="muted small"><?= $view->e($p['months']['basis']) ?></p></div>
    </div>
    <details><summary>Competitor articles and searches</summary>
        <div class="table-scroll"><table class="table table-dense" data-no-table-controls><thead><tr><th scope="col">Competitor article</th><th scope="col" class="num">Best position</th><th scope="col" class="num">Est. traffic/month</th><th scope="col" class="num">Searches</th></tr></thead><tbody>
        <?php foreach ($idea->competitors as $c): ?><tr><td><?= $view->e($c['domain']) ?><?= $c['found'] ? ' <span class="muted small">(found)</span>' : '' ?><?= $split && isset($c['market']) ? ' <span class="muted small">(' . $view->e(ArticleIdea::label($c['market'])) . ')</span>' : '' ?><br><a href="<?= $view->e($c['url']) ?>" rel="noopener noreferrer" target="_blank" class="small"><?= $view->e($path($c['url'])) ?></a></td><td class="num"><?= $view->e($c['position'] ?? '—') ?></td><td class="num"><?= $view->e(number_format($c['traffic'])) ?></td><td class="num"><?= (int) $c['keywords'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="small"><?php foreach ($idea->keywords as $i => $k): ?><?= $i > 0 ? ' · ' : '' ?><?= $view->e($k['keyword']) ?> <span class="muted">(<?= $view->e(number_format($k['volume'])) ?><?php if (count($k['volumes'] ?? []) > 1): ?>: <?php $vi = 0; foreach ($k['volumes'] ?? [] as $vm => $vv): ?><?= $vi++ > 0 ? ' + ' : '' ?><?= $view->e(ArticleIdea::label((string) $vm) . ' ' . number_format($vv)) ?><?php endforeach; ?><?php endif; ?><?= $k['difficulty'] !== null ? ', KD ' . (int) $k['difficulty'] : '' ?>)</span><?php endforeach; ?></p>
        <?php if ($idea->own !== null): ?><p class="small">Your page: <a href="<?= $view->e($idea->own['url']) ?>" rel="noopener" target="_blank"><?= $view->e($path($idea->own['url'])) ?></a><?= $idea->own['position'] !== null ? ', around position ' . $view->e(number_format($idea->own['position'], 1)) : '' ?>.</p><?php endif; ?>
    </details>
    <?php $outline = $outlines[$idea->key] ?? null; ?>
    <?php if ($outline !== null): ?>
    <div class="article-idea-outline">
        <h3>Subtopics</h3>
        <?php if ($outline->status === ArticleOutline::QUEUED): ?>
            <p class="muted small">Reading the competitor outlines (requested <?= $view->e(substr($outline->requestedAt, 0, 16)) ?> UTC)…</p>
        <?php elseif ($outline->status === ArticleOutline::FAILED): ?>
            <p class="small">The outlines could not be read: <?= $view->e((string) $outline->error) ?></p>
        <?php else: ?>
            <?php $subtopics = $outline->subtopics(); $readable = count($outline->sites()); ?>
            <?php if ($subtopics === []): ?>
                <p class="muted small">The competitor articles share no subtopics in their headings.</p>
            <?php else: ?>
                <p class="muted small"><?= $readable === 1 ? 'The headings of the one competitor site whose articles could be read (headings its articles repeat are left out as site template).' : 'Covered by at least two of the ' . $readable . ' competitor sites read, most widely covered first. Headings a site repeats on its articles are left out as its template.' ?><?= $outline->own !== null ? ' ✓ marks subtopics your page already has.' : '' ?></p>
                <ul class="small"><?php foreach ($subtopics as $subtopic): ?><li><?= $view->e($subtopic['topic']) ?> <span class="muted">(<?= count($subtopic['competitors']) ?> of <?= $readable ?>)</span><?= $outline->own !== null ? ($subtopic['own'] ? ' <span aria-label="on your page">✓</span>' : ' <span class="muted">· missing on your page</span>') : '' ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php $questions = $outline->questions($idea->mainKeyword); ?>
            <?php if ($questions !== []): ?><p class="small"><strong>Questions they answer:</strong> <?= $view->e(implode(' · ', $questions)) ?></p><?php endif; ?>
            <details><summary>Competitor outlines (read <?= $view->e(substr((string) $outline->fetchedAt, 0, 10)) ?>)</summary>
                <?php foreach ($outline->pages as $page): ?>
                    <p class="small"><a href="<?= $view->e($page->url) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e($path($page->url)) ?></a> <span class="muted">· <?= $page->readable() ? $view->e(number_format($page->wordCount)) . ' words, ' . count($page->outline) . ' headings' : 'not read: ' . $view->e((string) $page->reason) ?></span></p>
                    <?php if ($page->outline !== []): ?><ul class="small article-idea-headings"><?php foreach (array_slice($page->outline, 0, 40) as $h): ?><li class="<?= $h['level'] === 3 ? 'is-sub' : '' ?>"><?= $view->e($h['text']) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <?php endforeach; ?>
                <p class="muted small">Only headings and word counts are read and kept, never the text. Pages blocked by robots.txt are not read.</p>
            </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($can_edit): ?>
    <div class="inline-form">
        <?php if ($outline === null || $outline->status === ArticleOutline::FAILED || ($outline->status === ArticleOutline::DONE && (string) $outline->fetchedAt < $outline_fresh_since)): ?>
        <form method="post" action="<?= $view->e($base . '/' . $idea->key . '/outline') ?>"><?= $view->csrfField() ?><button class="button button-small"><?= $outline === null ? 'Read competitor outlines' : 'Read outlines again' ?></button></form>
        <?php endif; ?>
        <a class="button button-small button-ghost" href="<?= $view->e($briefs . '?' . http_build_query(['article_idea' => $idea->key, 'keyword' => $idea->mainKeyword])) ?>">Create brief</a>
        <?php if (isset($plans[$idea->key])): ?>
        <a class="button button-small button-ghost" href="<?= $view->e('/projects/' . $project->publicId . '/topic-plans/' . $plans[$idea->key]) ?>">Open topic plan</a>
        <?php else: ?>
        <form method="post" action="<?= $view->e($base . '/' . $idea->key . '/plan') ?>"><?= $view->csrfField() ?><button class="button button-small button-ghost">Plan a topic</button></form>
        <?php endif; ?>
        <form method="post" action="<?= $view->e($base . '/' . $idea->key . '/dismiss') ?>"><?= $view->csrfField() ?><button class="button button-small button-ghost">Hide</button></form>
        <form method="post" action="<?= $view->e($base . '/' . $idea->key . '/dismiss') ?>"><?= $view->csrfField() ?><input type="hidden" name="reason" value="not_relevant"><button class="button button-small button-ghost">Not relevant</button></form>
    </div>
    <details class="article-idea-publish"><summary class="button button-small button-ghost">Mark as published</summary>
        <form method="post" action="<?= $view->e($base . '/' . $idea->key . '/published') ?>" class="article-idea-publish-form"><?= $view->csrfField() ?>
            <label class="small" for="pub-url-<?= $view->e(substr($idea->key, 0, 12)) ?>">Article address
                <input id="pub-url-<?= $view->e(substr($idea->key, 0, 12)) ?>" type="url" name="url" required placeholder="https://<?= $view->e($project->primaryDomain) ?>/blog/…" value="<?= $view->e($idea->own['url'] ?? '') ?>"></label>
            <label class="small" for="pub-date-<?= $view->e(substr($idea->key, 0, 12)) ?>">Published on
                <input id="pub-date-<?= $view->e(substr($idea->key, 0, 12)) ?>" type="date" name="published_on" required max="<?= $view->e($today) ?>" value="<?= $view->e($today) ?>"></label>
            <button class="button button-small">Track</button>
        </form>
    </details>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<p class="muted small">Estimates, not promises: traffic depends on the position you reach and on results-page features; time to rank is an assumption until SEOcc has enough of your own ranking history. Competitor data: DataForSEO estimates.</p>
</div>
