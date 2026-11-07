<?php

declare(strict_types=1);

/**
 * Improve → Internal links (ADR-040, F1).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var ?SeoControlCenter\Modules\SiteAudit\SiteAudit $audit
 * @var bool $recorded the audit kept link places and text (crawled with 0.25.0 or later)
 * @var bool $running a newer audit is crawling now
 * @var ?string $built_at when the suggestions were last built (UTC)
 * @var list<array{source_url: string, target_url: string, cluster_id: ?string, topic: string, anchor: string, paragraph: string, score: float}> $suggestions
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var string $search
 * @var string $url
 * @var ?array{audit: ?SeoControlCenter\Modules\SiteAudit\SiteAudit, url: ?string, found: bool, recorded: bool, in: list<array{url: string, anchor: ?string, place: ?string, context: ?string}>, out: list<array{url: string, anchor: ?string, place: ?string, context: ?string}>} $lookup
 */
$base = '/projects/' . $project->publicId;
$self = $base . '/site-audit/links';
$view->extend('layouts/app', ['title' => 'Internal links · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-audit']);
$link = static fn(int $p): string => $self . '?' . http_build_query(array_filter(['q' => $search, 'page' => $p > 1 ? $p : null], static fn(mixed $v): bool => $v !== null && $v !== ''));
// The matched words in a paragraph, highlighted; the rest stays plain text.
$highlight = static function (string $paragraph, string $anchor) use ($view): string {
    $at = $anchor !== '' ? mb_stripos($paragraph, $anchor) : false;
    if ($at === false) {
        return $view->e($paragraph);
    }

    return $view->e(mb_substr($paragraph, 0, $at)) . '<mark>' . $view->e(mb_substr($paragraph, $at, mb_strlen($anchor))) . '</mark>' . $view->e(mb_substr($paragraph, $at + mb_strlen($anchor)));
};
// Same-site addresses as their path, so they wrap at slashes in narrow columns.
$path = static function (string $url): string {
    $parts = parse_url($url);
    $out = (is_array($parts) ? (string) ($parts['path'] ?? '/') : $url) . (is_array($parts) && isset($parts['query']) ? '?' . $parts['query'] : '');

    return $out !== '' ? $out : '/';
};
$places = ['body' => 'Text', 'nav' => 'Menu', 'header' => 'Header', 'footer' => 'Footer', 'aside' => 'Sidebar'];
$confidence = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow">Improve · Internal links</p><h2>Internal links</h2><p class="muted">Where your pages already mention a topic another page owns, without linking to it yet. From the newest completed site audit<?= $audit !== null ? ' (' . $view->e(substr((string) ($audit->finishedAt ?? $audit->createdAt), 0, 10)) . ')' : '' ?> and your <a href="<?= $view->e($base . '/keywords/topics') ?>">topics and owner pages</a>. Free: nothing is bought.</p></div></header>

<?php if ($audit === null): ?>
<section class="card"><h2>No completed site audit yet</h2><p class="muted">Suggestions and the link graph come from the free site audit. <?= $running ? 'An audit is running now; suggestions follow within the hour after it completes.' : '<a href="' . $view->e($base . '/site-audit') . '">Run a site audit</a>; suggestions follow within the hour after it completes.' ?></p></section>
<?php else: ?>
<?php if (!$recorded): ?>
<section class="card" role="status"><h2>This audit has no link text yet</h2><p class="muted">The newest completed audit (<?= $view->e(substr((string) ($audit->finishedAt ?? $audit->createdAt), 0, 10)) ?>) was crawled before SEOcc recorded anchors, paragraphs and where each link sits, so it can't give suggestions. <?= $running ? 'A new audit is running now; suggestions follow within the hour after it completes.' : '<a href="' . $view->e($base . '/site-audit') . '">Run a new site audit</a> (free); suggestions follow within the hour after it completes.' ?> Until then, <em>Links of one page</em> below shows which pages link where, without anchors or paragraphs.</p></section>
<?php else: ?>
<section class="card" aria-labelledby="suggestions-heading">
    <div class="sync-row"><div><h2 id="suggestions-heading">Link suggestions</h2><p class="muted small">Each row: a paragraph on one of your pages that mentions a topic (one of its searches, as whole words) whose owner page it does not link to from its text yet. Links in the menu, header, footer or sidebar don’t count: they don’t replace a link in the text. Add the link around the highlighted words or similar wording. Stronger topics and pages that already get many internal links come first.</p><p class="muted small"><?= $built_at !== null ? 'Built ' . $view->e(substr($built_at, 0, 16)) . ' UTC from this audit; rebuilt after every audit and daily.' : 'Not built yet for this audit: the background job runs within the hour after an audit completes, then daily.' ?></p></div><span class="indexing-badge"><?= $view->e(number_format($total)) ?> suggestions</span></div>
    <form method="get" action="<?= $view->e($self) ?>" class="inline-form" role="search"><label for="link-search" class="small">Topic or page address contains</label><input id="link-search" name="q" type="search" maxlength="200" value="<?= $view->e($search) ?>"><button class="button button-small">Filter</button><?php if ($search !== ''): ?> <a href="<?= $view->e($self) ?>">Reset</a><?php endif; ?></form>
    <?php if ($suggestions === []): ?>
        <p class="muted"><?= $search !== '' ? 'No suggestion matches this filter.' : ($built_at === null ? 'Suggestions appear here once they are built.' : 'No suggestions. Either your pages already link from their text where they mention a topic, no topic has an owner page yet, or no paragraph mentions a topic’s searches word for word.') ?></p>
    <?php else: ?>
    <div class="table-scroll"><table class="table"><thead><tr><th scope="col">Add a link on</th><th scope="col">Paragraph</th><th scope="col">Link to</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
    <?php foreach ($suggestions as $row): ?>
        <tr>
            <td><a href="<?= $view->e($row['source_url']) ?>" rel="noopener" target="_blank" title="<?= $view->e($row['source_url']) ?>"><?= $view->e($path($row['source_url'])) ?></a><br><a class="small" href="<?= $view->e($self . '?url=' . rawurlencode($row['source_url'])) ?>">Links of this page</a></td>
            <td><?= $highlight($row['paragraph'], $row['anchor']) ?><br><span class="muted small">Anchor: “<?= $view->e($row['anchor']) ?>”</span></td>
            <td><a href="<?= $view->e($row['target_url']) ?>" rel="noopener" target="_blank" title="<?= $view->e($row['target_url']) ?>"><?= $view->e($path($row['target_url'])) ?></a><br><span class="muted small">Owner of <?= $row['cluster_id'] !== null ? '<a href="' . $view->e($base . '/keywords/topics?q=' . rawurlencode($row['topic'])) . '">' . $view->e($row['topic']) . '</a>' : $view->e($row['topic']) ?></span></td>
            <td><button type="button" class="button button-small button-ghost" data-ask-ai="<?= $view->e('Rewrite this paragraph from ' . $row['source_url'] . ' so it links naturally to ' . $row['target_url'] . ' (the page for “' . $row['topic'] . '”). Keep the meaning and length, suggest the anchor text, and give the HTML of the changed sentence only. Paragraph: ' . $row['paragraph']) ?>">Reword with AI</button></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($pages > 1): ?><nav class="pagination" aria-label="Pages"><?php if ($page > 1): ?><a href="<?= $view->e($link($page - 1)) ?>">Previous</a><?php endif; ?><span><?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a href="<?= $view->e($link($page + 1)) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
    <p class="muted small">“Reword with AI” only fills in a question for Ask AI; nothing is sent until you send it.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (isset($coverage) && $audit !== null && $recorded): ?>
<section class="card" aria-labelledby="link-coverage-heading">
    <h2 id="link-coverage-heading">What the link evidence covers</h2>
    <p class="small">Crawl of <?= $view->e(substr((string) $coverage['crawled_at'], 0, 16)) ?> UTC: <?= number_format((int) $coverage['pages_crawled']) ?> pages crawled<?= $coverage['max_pages'] !== null ? ' of a ' . number_format((int) $coverage['max_pages']) . '-page limit' : '' ?>.<?= ($built_at ?? null) !== null ? ' Suggestions built ' . $view->e(substr((string) $built_at, 0, 16)) . ' UTC.' : '' ?><?php if (($left_out ?? null) !== null && $left_out > 0): ?> <?= number_format($left_out) ?> match<?= $left_out === 1 ? ' was' : 'es were' ?> left out because the paragraph is about something else than the target page (for example reward points against a prize draw).<?php endif; ?></p>
    <ul class="small muted"><?php foreach ($coverage['limits'] as $limit): ?><li><?= $view->e($limit) ?></li><?php endforeach; ?></ul>
    <p class="small muted">No suggestion for a page does not prove it is well linked: pages beyond the crawl limit, topics without an owner page and text outside the read paragraphs are not checked.</p>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="page-links-heading" id="page-links">
    <h2 id="page-links-heading">Links of one page</h2>
    <form method="get" action="<?= $view->e($self) ?>#page-links" class="inline-form"><label for="link-url" class="small">Page address</label><input id="link-url" name="url" type="url" maxlength="2048" required value="<?= $view->e($url) ?>" placeholder="<?= $view->e('https://' . $project->primaryDomain . '/') ?>"><button class="button button-small">Show links</button></form>
    <?php if ($lookup !== null): ?>
        <?php if (!$lookup['found']): ?>
            <p class="muted">This address was not crawled in the newest audit. Use the exact address as the audit found it, including the trailing slash if there is one.</p>
        <?php else: ?>
            <?php if (!$lookup['recorded']): ?><p class="muted small">This audit was crawled before anchors, places and paragraphs were recorded; a new audit fills them in.</p><?php endif; ?>
            <?php foreach (['in' => 'Links to this page', 'out' => 'Links from this page'] as $direction => $heading): ?>
            <h3><?= $view->e($heading) ?> <span class="muted small">(<?= count($lookup[$direction]) ?><?= count($lookup[$direction]) >= 200 ? ', first 200' : '' ?>)</span></h3>
            <?php if ($lookup[$direction] === []): ?><p class="muted small">None.</p><?php else: ?>
            <ul class="link-class-summary"><?php foreach ($lookup[$direction . '_by_class'] as $class => $group): ?><li><strong><?= $view->e($group['label']) ?></strong>: <?= number_format($group['count']) ?><?= $class === 'template' && $group['pages'] > $group['count'] ? ' <span class="muted small">(the same template links on ' . number_format($group['pages']) . ' pages, counted once)</span>' : '' ?></li><?php endforeach; ?></ul>
            <?php if (isset($lookup[$direction . '_by_class']['unclassified'])): ?><p class="muted small">This audit was crawled before SEOcc classed links, so links in the page body are not yet split into text links, related-article cards and product cards. The next site audit fills that in.</p><?php endif; ?>
            <?php foreach ($lookup[$direction . '_by_class'] as $class => $group): ?>
            <details class="link-class-group"<?= $class === 'editorial' ? ' open' : '' ?>><summary><?= $view->e($group['label']) ?> (<?= number_format($group['count']) ?>)</summary>
            <div class="table-scroll"><table class="table table-dense" data-no-table-controls><thead><tr><th scope="col"><?= $direction === 'in' ? 'From' : 'To' ?></th><th scope="col">Where</th><th scope="col">Confidence</th><th scope="col">Anchor</th><th scope="col">Paragraph</th></tr></thead><tbody>
            <?php foreach ($group['links'] as $row): ?>
                <tr><td><a href="<?= $view->e($self . '?url=' . rawurlencode($row['url'])) ?>#page-links"><?= $view->e($row['url']) ?></a></td><td><?= $view->e($places[$row['place'] ?? ''] ?? ($lookup['recorded'] ? 'Redirect' : 'Not recorded')) ?></td><td><?= $view->e($confidence[$row['confidence'] ?? ''] ?? '—') ?></td><td><?= $view->e($row['anchor'] ?? '—') ?></td><td class="small"><?= $view->e($row['context'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div></details>
            <?php endforeach; ?>
            <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
