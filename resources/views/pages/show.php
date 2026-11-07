<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\View\Template $view */
/** @var SeoControlCenter\Domain\Projects\Project $project */
/** @var array<string, mixed> $details */
$base = '/projects/' . $project->publicId;
$view->extend('layouts/app', ['title' => 'Page details · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'pages']);
?>
<div class="research-workspace research-pages research-page-details">
<p><a href="<?= $view->e($base . '/pages') ?>">All pages</a></p>
<section class="card">
    <h2 class="brief-url"><?= $view->e($details['url']) ?></h2>
    <p><a class="button button-ghost button-small" href="<?= $view->e($base . '/content-briefs/new?' . http_build_query(['url' => $details['url']])) ?>">Create content brief</a></p>
    <button type="button" class="button button-small" data-assistant-ask="<?= $view->e('Use page_details for project ' . $project->publicId . ' and URL ' . $details['url'] . '. Explain the main problems and suggest fixes supported by its stored evidence.') ?>">Ask AI about this page</button>
    <p class="muted">Search window: <?= $view->e($details['range']['from']) ?> to <?= $view->e($details['range']['to']) ?>. <?= $view->e($details['bing_note']) ?></p>
    <?= $view->partial('partials/annotation-context', ['records' => $details['annotations'], 'project' => $project]) ?>
    <p><a href="<?= $view->e('/projects/' . $project->publicId . '/annotations?' . http_build_query(['url' => $details['url']])) ?>">Record or review changes to this page</a></p>
    <p><a href="<?= $view->e($base . '/page-monitoring?url=' . rawurlencode($details['url'])) ?>">Monitor this page for weekly SEO changes</a></p>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Engine</th><th scope="col">Clicks</th><th scope="col">Impressions</th><th scope="col">Average position</th></tr></thead><tbody>
    <?php foreach (['google' => 'Google', 'bing' => 'Bing'] as $engine => $label): $metrics = $details[$engine]; ?>
        <tr><th scope="row"><?= $view->e($label) ?></th><td><?= $metrics !== null ? $view->e(number_format($metrics['clicks'])) : '—' ?></td><td><?= $metrics !== null ? $view->e(number_format($metrics['impressions'])) : '—' ?></td><td><?= $metrics !== null ? $view->e(number_format($metrics['position'], 1)) : '—' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</section>
<section class="card" aria-labelledby="page-improvements-title">
    <h2 id="page-improvements-title">What to improve</h2>
    <p class="muted">Start with the highest-priority candidate below. Each suggestion includes its stored evidence and a next action. Review source dates and search intent before changing this page.</p>
    <?php if (!$details['traffic_comparison']['available']): ?><p class="muted">Traffic trend unavailable: both 28-day periods need complete Google page imports and stored metrics for this URL.</p><?php endif; ?>
    <?php if ($details['analysis_limited'] || $details['opportunities_limited']): ?><p role="status">Some source lists reached their analysis limits. Review the evidence sections below for details.</p><?php endif; ?>
    <?php if ($details['improvements'] === []): ?><p>No improvement candidates in the available evidence. Missing or older source data can hide problems; this is not a clean bill of health.</p><?php else: ?>
    <ol>
        <?php foreach ($details['improvements'] as $improvement): ?><li>
            <h3><?= $view->e($improvement['title']) ?></h3>
            <ul><?php foreach ($improvement['evidence'] as $evidence): ?><li><?= $view->e($evidence) ?></li><?php endforeach; ?></ul>
            <p><strong>Next action:</strong> <?= $view->e($improvement['next_action']) ?></p>
            <p><a href="#<?= $view->e($improvement['section']) ?>">Review supporting evidence</a></p>
        </li><?php endforeach; ?>
    </ol>
    <?php endif; ?>
</section>
<section class="card" id="page-indexing"><h2>Google indexing</h2><p><?= $view->e($details['google_indexing_notice']) ?></p>
<?php $indexEvidence = $details['google_indexing']; if ($indexEvidence === null): ?><p>No saved inspection for this URL on the current property.</p><?php else: ?><p><strong><?= $view->e(\SeoControlCenter\Modules\Indexing\IndexingService::FILTERS[$indexEvidence['status']]) ?></strong> · last successful check <?= $view->e($indexEvidence['checked_at'] ?? 'Not checked yet') ?> UTC</p><?php if ($indexEvidence['error'] !== null): ?><p><?= $view->e($indexEvidence['error']) ?></p><?php endif; ?><?php if ($indexEvidence['result'] !== null): ?><dl class="indexing-evidence"><?php foreach (['coverageState' => 'Coverage', 'lastCrawlTime' => 'Last Google crawl', 'googleCanonical' => 'Google-selected canonical', 'userCanonical' => 'User-declared canonical'] as $key => $label): ?><dt><?= $view->e($label) ?></dt><dd><?= $view->e($indexEvidence['result'][$key] ?: 'Not reported') ?></dd><?php endforeach; ?></dl><?php endif; ?><?php endif; ?>
<a class="button button-secondary" href="<?= $view->e($base . '/indexing?url=' . rawurlencode($details['url'])) ?>">Inspect or watch this URL</a></section>
<section class="card" id="page-search">
    <h2>Search queries for this URL</h2>
    <p class="muted">Joint Google query/page data. Google omits anonymized queries; these rows may not add up to page totals.</p>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Query</th><th scope="col">Clicks</th><th scope="col">Impressions</th><th scope="col">Average position</th></tr></thead><tbody>
    <?php foreach ($details['search_queries'] as $query): ?><tr><td><?= $view->e($query['query']) ?></td><td><?= $view->e($query['clicks']) ?></td><td><?= $view->e($query['impressions']) ?></td><td><?= $view->e(number_format($query['position'], 1)) ?></td></tr><?php endforeach; ?>
    <?php if ($details['search_queries'] === []): ?><tr><td colspan="4">No imported query/page data for this URL yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?= $view->partial('pages/outlook', ['base' => $base, 'details' => $details]) ?>
<section class="card"><h2>Conversions and revenue</h2><p>GA4 Organic Search attribution; conversions are GA4 key events. <a href="<?= $view->e($base . '/analytics') ?>">Open Analytics</a>.</p>
<?php if ($details['analytics'] === null): ?><p>No GA4 snapshot yet.</p><?php else: ?>
<?= $view->partial('analytics/metadata', ['metadata' => $details['analytics']['metadata']]) ?>
<p class="muted small">Imported coverage: <?= $details['analytics']['covered_days'] ?> / <?= $details['analytics']['expected_days'] ?> days in this page's period. Missing dates are unavailable, not zero.</p>
<?php $analyticsRow = $details['analytics']['metrics']; if ($analyticsRow !== null): ?><dl><dt>Sessions</dt><dd><?= $view->e(number_format($analyticsRow['sessions'])) ?></dd><dt>Conversions</dt><dd><?= $view->e(number_format($analyticsRow['conversions'], 2)) ?></dd><dt>Purchase revenue</dt><dd><?= $view->e(number_format($analyticsRow['revenue'], 2) . ' ' . $details['analytics']['metadata']['currency']) ?></dd></dl><?php else: ?><p>No row for this exact HTTPS landing URL in the stored GA4 snapshot.</p><?php endif; ?>
<?php endif; ?></section>
<?php foreach (['rankings' => 'Tracked keyword rankings', 'ai_overview_citations' => 'AI Overview citations'] as $key => $label): ?>
<section class="card"<?= $key === 'rankings' ? ' id="page-rankings"' : '' ?>><h2><?= $view->e($label) ?></h2>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Keyword</th><th scope="col">Position</th><th scope="col">Device</th><th scope="col">Checked on</th></tr></thead><tbody>
    <?php foreach ($details[$key] as $row): ?><tr><td><?= $view->e($row['keyword']) ?></td><td><?= $view->e($row['position'] ?? '—') ?></td><td><?= $view->e($row['device']) ?></td><td><?= $view->e($row['checked_on']) ?></td></tr><?php endforeach; ?>
    <?php if ($details[$key] === []): ?><tr><td colspan="4">No current stored results for this URL.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php endforeach; ?>
<section class="card" id="page-audit"><h2>Site audit</h2>
    <p><a href="<?= $view->e($base . '/site-audit') ?>">Open site audit and Core Web Vitals</a></p>
    <?php if ($details['crawl'] === null): ?><p>This URL was not checked in the latest completed audit.</p><?php else: ?>
    <p class="muted">Completed <?= $view->e($details['audit_finished_at'] ?? '') ?> UTC.</p>
    <dl><?php foreach (['status' => 'HTTP status', 'title' => 'Title', 'description' => 'Description', 'h1' => 'H1', 'canonical' => 'Canonical', 'word_count' => 'Word count'] as $key => $label): ?><dt><?= $view->e($label) ?></dt><dd><?= $view->e($details['crawl'][$key] ?? '—') ?></dd><?php endforeach; ?><dt>Noindex</dt><dd><?= $details['crawl']['noindex'] ? 'Yes' : 'No' ?></dd></dl>
    <?php foreach ($details['issues'] as $issue): ?><h3><?= $view->e($issue['label']) ?></h3><p><?= $view->e($issue['severity']) ?> · <?= $view->e($issue['detail'] ?? '') ?></p><p><?= $view->e($issue['advice']) ?></p><?php endforeach; ?>
    <?php if ($details['issues'] === []): ?><p>No recorded issues for this URL.</p><?php endif; ?>
    <?php endif; ?>
</section>
<section class="card"><h2>Core Web Vitals</h2>
    <?php foreach (['PHONE' => 'Mobile', 'DESKTOP' => 'Desktop'] as $formFactor => $deviceLabel): ?>
    <?= $view->partial('web-vitals/record', ['record' => $details[$formFactor === 'PHONE' ? 'web_vitals' : 'web_vitals_desktop'], 'formFactor' => $formFactor]) ?>
    <?php if ($canCheckVitals): ?>
    <form method="post" action="<?= $view->e($base . '/web-vitals') ?>"><?= $view->csrfField() ?><input type="hidden" name="url" value="<?= $view->e($details['url']) ?>"><input type="hidden" name="form_factor" value="<?= $view->e($formFactor) ?>"><button class="button button-small" type="submit">Check <?= $view->e(strtolower($deviceLabel)) ?> Web Vitals</button></form>
    <form method="post" action="<?= $view->e($base . '/web-vitals/watch') ?>"><?= $view->csrfField() ?><input type="hidden" name="url" value="<?= $view->e($details['url']) ?>"><input type="hidden" name="form_factor" value="<?= $view->e($formFactor) ?>"><label><input type="checkbox" name="enabled" value="1" <?= $details['web_vitals_weekly'][$formFactor] ? 'checked' : '' ?>> Refresh <?= $view->e(strtolower($deviceLabel)) ?> weekly</label><button class="button button-small" type="submit">Save refresh</button></form>
    <?php endif; ?>
    <?php $fieldHistory = $details[$formFactor === 'PHONE' ? 'web_vitals_history' : 'web_vitals_desktop_history']; ?>
    <?php if ($fieldHistory !== []): ?><details><summary><?= $view->e($deviceLabel) ?> history (latest 52 checks)</summary><div class="table-scroll"><table><thead><tr><th>Fetched UTC</th><th>Collection period</th><th>Status</th><th>LCP ms</th><th>INP ms</th><th>CLS</th></tr></thead><tbody><?php foreach ($fieldHistory as $snapshot): ?><tr><td><?= $view->e($snapshot['fetched_at']) ?></td><td><?= $view->e(($snapshot['period_from'] ?? '—') . ' – ' . ($snapshot['period_to'] ?? '—')) ?></td><td><?= $view->e($snapshot['status']) ?></td><td><?= $view->e($snapshot['lcp_ms'] ?? 'Unavailable') ?></td><td><?= $view->e($snapshot['inp_ms'] ?? 'Unavailable') ?></td><td><?= $view->e($snapshot['cls'] ?? 'Unavailable') ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
    <?php endforeach; ?>
</section>
<section class="card" id="page-opportunities"><h2>Open opportunities</h2>
    <?php if ($details['opportunities_limited']): ?><p role="status">Matches use the 1,000 highest-priority open opportunities. More may be listed under Opportunities.</p><?php endif; ?>
    <?php foreach ($details['opportunities'] as $opportunity): ?><h3><a href="<?= $view->e($base . '/opportunities') ?>"><?= $view->e($opportunity['title']) ?></a></h3><p><?= $view->e($opportunity['explanation']) ?></p><?php endforeach; ?>
    <?php if ($details['opportunities'] === []): ?><p>No open opportunities matched to this URL or its queries.</p><?php endif; ?>
</section>
<section class="card" id="page-cannibalization"><h2>Possible cannibalization</h2>
    <?php if ($details['analysis_limited']): ?><p role="status">Analysis uses the 5,000 query/page pairs with the most impressions.</p><?php endif; ?>
    <?php foreach ($details['cannibalization'] as $group): ?><h3><?= $view->e($group['query']) ?></h3><ul><?php foreach ($group['pages'] as $row): ?><li><a href="<?= $view->e($base . '/pages/details?url=' . rawurlencode($row['url'])) ?>"><?= $view->e($row['url']) ?></a> · average position <?= $view->e(number_format($row['position'], 1)) ?> · <?= $view->e($row['impressions']) ?> impressions</li><?php endforeach; ?></ul><?php endforeach; ?>
    <?php if ($details['cannibalization'] === []): ?><p>No candidates in the imported data.</p><?php endif; ?>
</section>
<section class="card" id="page-internal-links"><h2>Internal link suggestions</h2>
    <p class="muted">Pages with at least two incoming crawl links and a title or H1 related to a keyword where this URL ranks 4–20. Existing links, non-indexable pages and alternate canonicals are excluded. Review the content before adding a link.</p>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Suggested source</th><th scope="col">Incoming links</th><th scope="col">Matching terms</th></tr></thead><tbody>
    <?php foreach ($details['internal_link_suggestions'] as $link): ?><tr><td><a href="<?= $view->e($base . '/pages/details?url=' . rawurlencode($link['url'])) ?>"><?= $view->e($link['url']) ?></a></td><td><?= $view->e($link['inbound_links']) ?></td><td><?= $view->e(implode(', ', $link['matching_terms'])) ?></td></tr><?php endforeach; ?>
    <?php if ($details['internal_link_suggestions'] === []): ?><tr><td colspan="3">No eligible suggestions in the latest completed crawl.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<section class="card"><h2>Backlinks</h2><p><?= $view->e($details['backlinks_note']) ?></p>
    <?php foreach ($details['backlink_fetched_at'] as $kind => $date): ?><p class="muted"><?= $view->e($kind === 'bl_links' ? 'Live links' : 'Lost links') ?> fetched <?= $view->e($date) ?> UTC</p><?php endforeach; ?>
    <div class="table-scroll"><table class="table"><thead><tr><th scope="col">Source URL</th><th scope="col">Anchor</th><th scope="col">Domain rank</th><th scope="col">Status</th><th scope="col">Lost on</th></tr></thead><tbody>
    <?php foreach ($details['backlinks'] as $link): ?><tr><td><?= $view->e($link['source_url']) ?></td><td><?= $view->e($link['anchor']) ?></td><td><?= $view->e($link['domain_rank'] ?? '—') ?></td><td><?= $view->e($link['status']) ?> · <?= $link['dofollow'] ? 'follow' : 'nofollow' ?></td><td><?= $view->e($link['lost_on'] ?? '—') ?></td></tr><?php endforeach; ?>
    <?php if ($details['backlinks'] === []): ?><tr><td colspan="5">No links to this exact URL in the stored sample.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

</div>
