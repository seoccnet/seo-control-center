<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<SeoControlCenter\Domain\Opportunities\StoredOpportunity> $opportunities
 * @var string $status
 * @var SeoControlCenter\Domain\Opportunities\OpportunityType|null $type
 * @var array<string, int> $openByType
 * @var array<string, int> $statusCounts
 * @var int $page
 * @var int $pages
 * @var string|null $lastAnalyzedAt
 * @var array<string, array{status: string, date: ?string, notice?: string}> $coverage
 * @var SeoControlCenter\Domain\Opportunities\OpportunityFilter $filters
 * @var bool $catalogTruncated
 * @var bool $canEdit
 * @var SeoControlCenter\Domain\Opportunities\BusinessContext|null $business
 * @var array<string, int> $ratings
 * @var list<SeoControlCenter\Domain\ArticleIdeas\ArticleIdea>|null $articleIdeas
 */
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\PriorityBand;

$view->extend('layouts/app', ['title' => 'Opportunities · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [null, 'Opportunities']]]);
$base = '/projects/' . $project->publicId;
$self = $base . '/opportunities';
$statusLabels = ['open' => 'Open', 'done' => 'Done', 'dismissed' => 'Dismissed', 'resolved' => 'Resolved', 'snoozed' => 'Snoozed'];
$effortLabels = [1 => 'low', 2 => 'medium', 3 => 'high'];
$query = static fn(array $params): string => '?' . http_build_query(array_filter($params, static fn($v): bool => $v !== null && $v !== ''));
$filterQuery = ['page_url' => $filters->pageUrl, 'min_impact' => $filters->minImpact ?: null, 'effort' => $filters->effort, 'data_basis' => $filters->basis, 'verdict' => $filters->verdict, 'zero_click' => $filters->zeroClick];
$current = $query([...$filterQuery, 'status' => $status !== 'open' ? $status : null, 'type' => $type?->value, 'page' => $page > 1 ? $page : null]);
$verdictLabels = ['target' => 'Target', 'consider' => 'Consider', 'reconsider' => 'Reconsider'];
$riskLabels = ['high' => 'High zero-click risk', 'medium' => 'Medium zero-click risk', 'low' => 'Low zero-click risk'];
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'opportunities']) ?>
<div class="insight-workspace opportunities-workspace">

<?php if ($catalogTruncated): ?><p class="notice">The catalog exceeds 5,000 stored findings. Open findings are withheld because duplicate workflow decisions cannot be checked completely.</p><?php endif; ?>
<header class="insight-intro"><div><p class="portfolio-eyebrow">Your next moves</p><h2>Turn findings into progress</h2><p class="muted">Prioritize the evidence, choose an action, and track the work.</p></div><nav class="opportunity-shortcuts" aria-label="Opportunity reports"><a href="<?= $view->e($self . '/by-page') ?>">Group by page</a><a href="<?= $view->e($self . '/weekly') ?>">Top 10 this week</a><a href="<?= $view->e($self . '/outcomes') ?>">Predicted vs observed</a></nav></header>
<nav class="insight-segments" aria-label="Opportunity views"><a class="chip" aria-current="page" href="<?= $view->e($self) ?>">SEO findings</a><a class="chip" href="<?= $view->e($self . '/tasks') ?>">Team tasks</a></nav>

<section class="opportunity-section" aria-labelledby="opportunity-overview-title"><div class="section-header"><div><p class="portfolio-eyebrow">Workflow overview</p><h2 id="opportunity-overview-title">Your opportunity pipeline</h2></div></div>
<div class="insight-metric-grid opportunity-state-grid" aria-label="Opportunity status overview">
<?php foreach ($statusLabels as $key=>$label): ?><a class="card insight-metric opportunity-state-<?= $view->e($key) ?>"<?= $key === $status ? ' aria-current="true"' : '' ?> href="<?= $view->e($self . $query([...$filterQuery, 'status'=>$key !== 'open' ? $key : null])) ?>"><p class="portfolio-eyebrow"><?= $view->e($label) ?></p><strong><?= number_format($statusCounts[$key] ?? 0) ?></strong><p class="muted small"><?= $view->e(['open'=>'Ready to review','done'=>'Marked complete by your team','dismissed'=>'Set aside by your team','resolved'=>'No longer detected by the rules','snoozed'=>'Set aside until a chosen date'][$key]) ?></p></a><?php endforeach; ?>
</div>
</section>
<section class="card sync-status opportunity-analysis">
    <div class="sync-row">
        <div>
            <strong><?= $view->e($statusCounts['open'] ?? 0) ?> open</strong>
            <span class="muted small">· <?= $lastAnalyzedAt !== null ? 'last analysis ' . $view->e($lastAnalyzedAt) . ' UTC, runs every 6 hours' : 'not analyzed yet; runs every 6 hours' ?></span>
        </div>
        <?php if ($canEdit): ?>
            <form method="post" action="<?= $view->e($self) ?>/analyze">
                <?= $view->csrfField() ?>
                <button type="submit" class="button button-small">Analyze now</button>
            </form>
        <?php endif; ?>
    </div>
    <p class="muted small">Opportunities are found by fixed rules on all stored data: Search Console and Bing, rankings, competitors, the site audit, keyword research, backlinks, AI visibility and local results. Each one shows the data behind it. Analysis is free; it sends nothing to DataForSEO.</p>
    <details><summary>Data coverage at the last analysis</summary>
    <p class="muted small">Missing or stale sources preserve existing findings and scores. Findings may need a fresh import before acting.</p>
    <ul><?php foreach ($coverage as $source => $item): ?><li><?= $view->e(str_replace('_', ' ', $source)) ?>: <?= $view->e(\SeoControlCenter\Modules\Opportunities\CoverageLabel::status($item)) ?><?= $item['date'] !== null ? ' · data ends ' . $view->e($item['date']) : '' ?><?= isset($item['notice']) ? ' · ' . $view->e($item['notice']) : '' ?></li><?php endforeach; ?></ul>
    </details>
</section>

<section class="card opportunity-business">
<?php if ($business === null): ?>
    <h2>Score findings against your business</h2>
    <p class="muted small">Describe what the business sells and what search should bring. Each keyword finding then shows a BID check (business potential, intent and format fit, difficulty you can beat) with a Target, Consider or Reconsider verdict, and the impact uses what the search is worth to you.</p>
    <p><a class="button button-small" href="<?= $view->e($self . '/business') ?>">Set up the business profile</a></p>
<?php else: ?>
    <div class="sync-row"><div><strong>Business profile</strong> <span class="muted small">· <?= $view->e(SeoControlCenter\Domain\Opportunities\BusinessContext::MODELS[$business->model] ?? $business->model) ?> · goal: <?= $view->e(mb_strtolower(SeoControlCenter\Domain\Opportunities\BusinessContext::GOALS[$business->goal] ?? $business->goal)) ?> · difficulty limit <?= $view->e($business->difficultyLimit()) ?> · <?= $view->e(count($ratings)) ?> keyword rating<?= count($ratings) === 1 ? '' : 's' ?></span></div><a class="button button-ghost button-small" href="<?= $view->e($self . '/business') ?>">Edit profile</a></div>
<?php endif; ?>
</section>

<?php if ($articleIdeas !== null && $articleIdeas !== []): ?>
<section class="card opportunity-article-ideas" aria-labelledby="opportunity-article-ideas-title">
    <div class="sync-row"><div><h2 id="opportunity-article-ideas-title">Top article ideas</h2><p class="muted small">From the searches your competitors' articles rank for. Estimates with ranges.</p></div><a class="button button-ghost button-small" href="<?= $view->e('/projects/' . $project->publicId . '/article-ideas') ?>">All article ideas</a></div>
    <ol class="small"><?php foreach ($articleIdeas as $idea): ?><li><strong><?= $view->e($idea->mainKeyword) ?></strong> · <?= $idea->kind === SeoControlCenter\Domain\ArticleIdeas\ArticleIdea::UPDATE ? 'update your article' : 'new article' ?> · <?= $view->e(number_format($idea->volume)) ?> searches/month · ~<?= $view->e(number_format($idea->prediction['traffic']['expected'])) ?> clicks/month · <?= $view->e($idea->prediction['difficulty']['level']) ?> difficulty · <?= $view->e($idea->prediction['months']['low'] . '–' . $idea->prediction['months']['high']) ?> months</li><?php endforeach; ?></ol>
</section>
<?php endif; ?>

<section id="opportunity-findings" class="opportunity-section" aria-labelledby="opportunity-findings-title">
<div class="section-header"><div><p class="portfolio-eyebrow">Review &amp; act</p><h2 id="opportunity-findings-title"><?= $view->e($statusLabels[$status] ?? 'Open') ?> findings</h2><p class="muted small">Use the scores and evidence to decide where to focus next.</p></div></div>
<nav class="insight-segments" aria-label="Status">
    <?php foreach ($statusLabels as $key => $label): ?>
        <a class="chip" href="<?= $view->e($self . $query([...$filterQuery, 'status' => $key !== 'open' ? $key : null, 'type' => $type?->value])) ?>"<?= $key === $status ? ' aria-current="true"' : '' ?>><?= $view->e($label) ?> (<?= $view->e($statusCounts[$key] ?? 0) ?>)</a>
    <?php endforeach; ?>
</nav>
<form class="insight-filter-form opportunity-filters" method="get" action="<?= $view->e($self) ?>"><input type="hidden" name="status" value="<?= $view->e($status) ?>"><div class="field"><label for="opportunity-type">Finding type</label><select name="type" id="opportunity-type"><option value="">All types</option><?php foreach (OpportunityType::cases() as $case): ?><option value="<?= $view->e($case->value) ?>"<?= $type === $case ? ' selected' : '' ?>><?= $view->e($case->label()) ?><?= isset($openByType[$case->value]) ? ' (' . $view->e($openByType[$case->value]) . ' open)' : '' ?></option><?php endforeach; ?></select></div><div class="field"><label for="opportunity-page-url">Page URL or path</label><input id="opportunity-page-url" name="page_url" value="<?= $view->e($filters->pageUrl) ?>" maxlength="2048"></div><div class="field"><label for="opportunity-min-impact">Minimum impact</label><input id="opportunity-min-impact" name="min_impact" type="number" min="0" max="100" value="<?= $view->e($filters->minImpact) ?>"></div><div class="field"><label for="opportunity-effort">Effort</label><select id="opportunity-effort" name="effort"><option value="">Any effort</option><?php foreach ([1 => 'Low', 2 => 'Medium', 3 => 'High'] as $value => $label): ?><option value="<?= $value ?>"<?= $filters->effort === $value ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></div><div class="field"><label for="opportunity-verdict">BID verdict</label><select id="opportunity-verdict" name="verdict"><option value="">Any verdict</option><?php foreach ($verdictLabels as $value => $label): ?><option value="<?= $view->e($value) ?>"<?= $filters->verdict === $value ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></div><div class="field"><label for="opportunity-zero-click">Zero-click risk</label><select id="opportunity-zero-click" name="zero_click"><option value="">Any risk</option><?php foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $label): ?><option value="<?= $view->e($value) ?>"<?= $filters->zeroClick === $value ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></div><div class="field"><label for="opportunity-basis">Data basis</label><select id="opportunity-basis" name="data_basis"><option value="">Any basis</option><?php foreach (SeoControlCenter\Domain\Opportunities\OpportunityFilter::BASES as $basis): ?><option value="<?= $view->e($basis) ?>"<?= $filters->basis === $basis ? ' selected' : '' ?>><?= $view->e(ucwords(str_replace('_', ' ', $basis))) ?></option><?php endforeach; ?></select></div><button class="button button-small" type="submit">Apply filter</button></form>
<?php if ($openByType !== []): ?><?php $rankedTypes=$openByType; arsort($rankedTypes); ?><details class="insight-disclosure"><summary>Where the open findings are</summary><?= $view->partial('partials/insight-bars',['title'=>'Open findings by type','note'=>'Five types with the most open findings across this project. Counts do not indicate impact.','bars'=>array_map(static fn($key): array=>['label'=>OpportunityType::from($key)->label(),'value'=>$rankedTypes[$key]],array_slice(array_keys($rankedTypes),0,5))]) ?></details><?php endif; ?>
<?php if ($opportunities !== []): ?>
    <div class="opportunity-list-head">
        <?= $view->partial('partials/priority-legend') ?>
        <p class="export-row"><a class="button button-ghost button-small" href="<?= $view->e($self . '/export' . $query([...$filterQuery, 'status' => $status, 'type' => $type?->value])) ?>"><?= $view->icon('download') ?>Export CSV</a></p>
    </div>
<?php endif; ?>

<?php if ($opportunities === []): ?>
    <section class="card">
        <p class="muted">
            <?php if ($status === 'open' && $lastAnalyzedAt === null): ?>
                No analysis yet. Opportunities need Search Console data or rank checks.
            <?php elseif ($status === 'open'): ?>
                No open opportunities<?= $type !== null ? ' of this type' : '' ?>. More appear as data accumulates: Search Console, rank checks, competitors, the site audit, keyword research, backlinks, AI visibility and local checks all feed the analysis.
            <?php else: ?>
                Nothing here.
            <?php endif; ?>
        </p>
    </section>
<?php endif; ?>

<?php foreach ($opportunities as $o): ?>
    <?php $band = PriorityBand::of($o->priority); ?>
    <article class="card opportunity insight-opportunity opportunity-type-<?= $view->e($o->type->value) ?> <?= $view->e($band->cssClass()) ?>">
        <header class="opportunity-header">
            <div>
                <div class="opportunity-badges"><span class="badge opportunity-type-badge"><?= $view->e($o->type->label()) ?></span><span class="badge opportunity-status opportunity-state-<?= $view->e($o->status) ?>"><?= $view->e($statusLabels[$o->status] ?? $o->status) ?></span><?php if ($o->verdict() !== null): ?><span class="badge opportunity-verdict opportunity-verdict-<?= $view->e($o->verdict()) ?>" title="<?= $view->e((string) $o->evidenceValue('BID verdict')) ?>"><?= $view->e($verdictLabels[$o->verdict()] ?? $o->verdict()) ?></span><?php endif; ?><?php if ($o->zeroClickRisk() !== null && $o->zeroClickRisk() !== 'low'): ?><span class="badge opportunity-zero-click opportunity-zero-click-<?= $view->e($o->zeroClickRisk()) ?>" title="<?= $view->e((string) $o->evidenceValue('Zero-click risk')) ?>"><?= $view->e($riskLabels[$o->zeroClickRisk()] ?? '') ?></span><?php endif; ?></div>
                <h2><?= $view->e($o->title) ?></h2>
            </div>
            <p class="opportunity-scores">
                <span class="opportunity-priority-label"><span class="priority-dot" aria-hidden="true"></span><?= $view->e($band->label()) ?> priority</span><strong><?= $view->e(number_format($o->priority, 0)) ?></strong>
                <span class="muted small">impact <?= $view->e($o->impact) ?> · confidence <?= $view->e($o->confidence) ?> % · effort <?= $view->e($effortLabels[$o->effort] ?? $o->effort) ?></span>
            </p>
        </header>
        <div class="insight-score-grid"><div class="opportunity-score-impact"><span>Impact</span><strong><?= $o->impact ?> / 100</strong><meter min="0" max="100" value="<?= $o->impact ?>" aria-label="Impact score"></meter></div><div class="opportunity-score-confidence"><span>Confidence</span><strong><?= $o->confidence ?> %</strong><meter min="0" max="100" value="<?= $o->confidence ?>" aria-label="Confidence score"></meter></div><div class="opportunity-score-effort"><span>Effort</span><strong><?= $view->e(ucfirst($effortLabels[$o->effort] ?? (string)$o->effort)) ?></strong><span class="muted small">Priority combines impact, confidence and effort</span></div></div><p class="insight-explanation"><?= $view->e($o->explanation) ?></p>
        <details class="insight-evidence"><summary>Evidence behind this finding · <?= count($o->evidence) ?> signals</summary><dl class="details">
            <?php foreach ($o->evidence as $item): ?>
                <dt><?= $view->e($item['label']) ?></dt><dd><?= $view->e($item['value']) ?></dd>
            <?php endforeach; ?>
        </dl></details>
        <?php $keyword = $o->keyword(); ?>
        <?php if ($canEdit && $keyword !== null && $business !== null): ?>
            <?php $rated = $ratings[SeoControlCenter\Domain\Keywords\Keyword::normalize($keyword)] ?? null; ?>
            <form method="post" action="<?= $view->e($self . '/keyword-rating') ?>" class="inline-form opportunity-rating"><?= $view->csrfField() ?><input type="hidden" name="keyword" value="<?= $view->e($keyword) ?>"><input type="hidden" name="back" value="<?= $view->e($current) ?>"><label for="rating-<?= $view->e($o->publicId) ?>" class="small">Business potential of “<?= $view->e($keyword) ?>”</label> <select id="rating-<?= $view->e($o->publicId) ?>" name="score"><option value="auto"<?= $rated === null ? ' selected' : '' ?>>Automatic<?= $rated === null && $o->businessPotential() !== null ? ' (' . $view->e($o->businessPotential()) . '/3)' : '' ?></option><?php foreach ([3 => '3 · drives the goal', 2 => '2 · supports it', 1 => '1 · weak', 0 => '0 · no value'] as $score => $label): ?><option value="<?= $score ?>"<?= $rated === $score ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select> <button type="submit" class="button button-ghost button-small">Save rating</button></form>
        <?php endif; ?>
        <div class="sync-row">
            <span class="muted small">First found <?= $view->e(substr($o->firstDetectedAt, 0, 10)) ?> · last seen <?= $view->e(substr($o->lastDetectedAt, 0, 10)) ?></span>
            <div class="actions">
                <a class="button button-ghost button-small" href="<?= $view->e($self . '/' . $o->publicId) ?>">Calculation and detail</a> <a class="button button-ghost button-small" href="<?= $view->e($self . '/' . $o->publicId . '/task') ?>">Manage task</a>
                <?php /* assistant.js swaps these when the AI assistant is set up: Ask AI appears and the brief button is hidden. Without the assistant (or JavaScript) the brief button stays. */ ?>
                <a class="button button-ghost button-small" href="<?= $view->e($base . '/content-briefs/new?' . http_build_query(['opportunity_id' => $o->publicId])) ?>" data-assistant-fallback>Create content brief</a>
                <button type="button" class="button button-small button-ghost" data-assistant-ask="<?= $view->e(sprintf('Help me with this opportunity: “%s” (opportunity_id %s). Explain the cause and give me the exact changes, page by page.', $o->title, $o->publicId)) ?>" hidden><?= $view->icon('chat') ?>Ask AI</button>
                <?php if ($canEdit): ?>
                    <?php foreach ($o->status === 'open' || $o->status === 'resolved' ? ['done' => 'Mark done', 'dismissed' => 'Dismiss'] : ['open' => 'Reopen'] as $to => $label): ?>
                        <form method="post" action="<?= $view->e($self) ?>/<?= $view->e($o->publicId) ?>/status" class="inline-form">
                            <?= $view->csrfField() ?>
                            <input type="hidden" name="status" value="<?= $view->e($to) ?>">
                            <input type="hidden" name="back" value="<?= $view->e($current) ?>">
                            <button type="submit" class="button button-small<?= $to === 'dismissed' ? ' button-ghost' : '' ?>"><?= $view->e($label) ?></button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </article>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Pages">
        <?php if ($page > 1): ?><a class="button button-small button-ghost" href="<?= $view->e($self . $query([...$filterQuery, 'status' => $status !== 'open' ? $status : null, 'type' => $type?->value, 'page' => $page - 1])) ?>">Previous</a><?php endif; ?>
        <span class="muted small">Page <?= $view->e($page) ?> of <?= $view->e($pages) ?></span>
        <?php if ($page < $pages): ?><a class="button button-small button-ghost" href="<?= $view->e($self . $query([...$filterQuery, 'status' => $status !== 'open' ? $status : null, 'type' => $type?->value, 'page' => $page + 1])) ?>">Next</a><?php endif; ?>
    </nav>
<?php endif; ?>

</section>
</div>
