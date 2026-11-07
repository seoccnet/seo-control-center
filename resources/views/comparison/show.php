<?php

declare(strict_types=1);

/**
 * "Why they outrank you" for one topic (ComparisonService::prepare).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Clusters\KeywordCluster $cluster
 * @var string $keyword
 * @var string $source stored|paid|none
 * @var float|null $cost
 * @var float|null $ai_cost
 * @var string|null $reason
 * @var SeoControlCenter\Domain\Comparison\Analysis|null $latest
 * @var bool $fresh
 * @var bool $can_run
 */

use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\ResultsFormat;

$base = '/projects/' . $project->publicId;
$self = $base . '/keywords/topics/' . $cluster->publicId . '/analysis';
$view->extend('layouts/app', ['title' => 'Why they outrank you · ' . $cluster->label]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'keywords']);
$done = $latest !== null && $latest->status === Analysis::DONE ? $latest : null;
$verdict = $done?->verdict ?? [];
$format = is_array($verdict['format'] ?? null) ? $verdict['format'] : null;
$ownType = is_array($verdict['own_type'] ?? null) ? $verdict['own_type'] : null;
$diagnosis = is_array($verdict['diagnosis'] ?? null) ? $verdict['diagnosis'] : [];
$coverage = is_array($verdict['coverage'] ?? null) ? $verdict['coverage'] : [];
$bottleneckLabel = ['format' => 'Wrong page type', 'content' => 'Content gaps', 'freshness' => 'Out of date', 'technical' => 'Page not readable', 'unclear' => 'Likely links or authority'];
$path = static fn(string $url): string => (string) preg_replace('#^https?://(www\.)?#', '', $url);
$explanation = $done?->explanation();
$ai = is_array($verdict['ai'] ?? null) ? $verdict['ai'] : null;
$linkPlan = is_array($verdict['links'] ?? null) ? $verdict['links'] : null;
$links = $done !== null ? $done->links() : [];
$roleLabel = ['money' => 'Main page', 'supporting' => 'Supporting page', 'hub' => 'Hub', 'competing' => 'Competing page'];
?>
<div class="research-workspace comparison-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Why they outrank you</p><h2><?= $view->e($cluster->label) ?></h2><p class="muted">What kind of page Google ranks for “<?= $view->e($keyword) ?>”, how the competing pages of that kind differ from yours, and what to change. The actions become opportunities.</p></div><nav class="research-shortcuts" aria-label="Analysis"><a href="<?= $view->e($base . '/keywords/topics#cluster-' . $cluster->publicId) ?>">Back to topics</a><a href="<?= $view->e($base . '/opportunities') ?>">Opportunities</a><?php if ($done !== null): ?><a href="<?= $view->e($self . '/report') ?>">Client report (PDF)</a><?php endif; ?></nav></header>

<section class="card sync-status"<?= $latest !== null && $latest->active() ? ' data-refresh-seconds="15"' : '' ?>>
    <?php if ($latest !== null && $latest->active()): ?>
        <p><strong><?= $latest->status === Analysis::RUNNING ? 'Analyzing…' : 'Queued' ?></strong> <span class="muted small">requested <?= $view->e($latest->requestedAt) ?> UTC; this page updates itself.</span></p>
    <?php elseif ($latest !== null && $latest->status === Analysis::FAILED): ?>
        <p class="notice notice-error">The last analysis failed: <?= $view->e((string) $latest->error) ?></p>
    <?php endif; ?>
    <?php if ($can_run && ($latest === null || !$latest->active())): ?>
        <?php if ($source === 'none'): ?>
            <p class="notice notice-warning"><?= $view->e((string) $reason) ?></p>
        <?php else: ?>
            <form method="post" action="<?= $view->e($self) ?>" class="inline-form">
                <?= $view->csrfField() ?>
                <?php if ($fresh): ?><input type="hidden" name="rerun" value="1"><?php endif; ?>
                <?php if ($source === Analysis::PAID): ?>
                    <label class="small"><input type="checkbox" name="confirm_cost" value="1" required> Look up the current results page for “<?= $view->e($keyword) ?>” (one DataForSEO request, about $<?= $view->e(number_format((float) $cost, 3)) ?>)</label>
                <?php endif; ?>
                <button type="submit" class="button button-small<?= $fresh ? ' button-ghost' : '' ?>"><?= $fresh ? 'Analyze again' : 'Analyze' ?></button>
                <span class="muted small"><?= $source === Analysis::STORED ? 'Free: uses the stored results page from rank tracking.' : 'Competing pages are read by your server, free.' ?>
                    <?= $ai_cost !== null ? 'The AI explanation costs about $' . $view->e(number_format($ai_cost, 3)) . ' from your AI budget.' : 'Without an AI assistant you get the rule-based result.' ?></span>
            </form>
        <?php endif; ?>
    <?php elseif (!$can_run): ?>
        <p class="muted small">Only members who may start analyses can run this.</p>
    <?php endif; ?>
</section>

<?php if ($done === null): ?>
    <?php if ($latest === null): ?><section class="card"><div class="empty-state"><h3>Not analyzed yet</h3><p>Analyze to compare the pages that rank for this topic with yours. Your server reads up to five of them and keeps only their structure.</p></div></section><?php endif; ?>
<?php else: ?>
<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Verdict · confidence <?= $view->e((string) ($diagnosis['confidence'] ?? 'low')) ?></p><h3><?= $view->e($bottleneckLabel[$done->bottleneck() ?? 'unclear'] ?? (string) $done->bottleneck()) ?></h3></div><span class="badge badge-info"><?= $view->e((string) $done->bottleneck()) ?></span></div>
    <?php if ($explanation !== null && is_string($explanation['verdict'] ?? null)): ?><p class="comparison-summary"><?= $view->e($explanation['verdict']) ?></p><?php endif; ?>
    <?php foreach ((array) ($diagnosis['reasons'] ?? []) as $line): ?><p<?= $explanation !== null ? ' class="muted small"' : '' ?>><?= $view->e((string) $line) ?></p><?php endforeach; ?>
    <?php if ($ai !== null && ($ai['status'] ?? null) !== 'done'): ?><p class="muted small"><?= $view->e((string) ($ai['note'] ?? '')) ?><?php if (($ai['status'] ?? null) === 'unavailable'): ?> <a href="/integrations/assistant">Set up the AI assistant</a><?php endif; ?></p><?php elseif ($ai !== null): ?><p class="muted small">Summary written by your AI assistant from the facts below; the findings themselves come from fixed rules.</p><?php endif; ?>
    <?php $dont = $explanation !== null && is_array($explanation['do_not_do'] ?? null) ? $explanation['do_not_do'] : []; ?>
    <?php if ($dont !== []): ?><p class="small"><strong>Avoid:</strong></p><ul class="small"><?php foreach ($dont as $line): ?><li><?= $view->e((string) $line) ?></li><?php endforeach; ?></ul><?php endif; ?>
</section>

<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">What Google wants</p><h3><?= $view->e($format !== null ? ucfirst((string) $format['verdict']) : '') ?></h3></div>
        <?php if ($ownType !== null && $format !== null): ?><?php $match = $format['verdict'] !== ResultsFormat::DOMINANT || ($format['types'][0] ?? null) === $ownType['type']; ?><span class="badge <?= $match ? 'badge-ok' : 'badge-warning' ?>">Your page: <?= $view->e(ResultsFormat::label((string) $ownType['type'])) ?> <?= $match ? '✓' : '⚠' ?></span><?php endif; ?></div>
    <p class="muted small"><?= $view->e($explanation !== null && is_string($explanation['format'] ?? null) && $explanation['format'] !== '' ? $explanation['format'] : (string) ($format['explanation'] ?? '')) ?></p>
    <ol class="comparison-strip">
    <?php foreach ($done->results as $r): ?>
        <li title="<?= $view->e(implode(', ', $r['signals'])) ?>"><span class="badge<?= $r['own'] ? ' badge-ok' : '' ?>"><?= (int) $r['position'] ?> · <?= $view->e(ResultsFormat::label($r['type'])) ?></span> <span class="small url-cell"><?= $view->e($path($r['url'])) ?></span><?php if ($r['fetched'] && isset($done->pages[$r['url']]) && !$done->pages[$r['url']]->readable()): ?> <span class="muted small">(<?= $view->e((string) $done->pages[$r['url']]->reason) ?>)</span><?php endif; ?><?php if ($r['own']): ?> <span class="muted small">you</span><?php endif; ?></li>
    <?php endforeach; ?>
    </ol>
</section>

<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Actions</p><h3><?= count($done->actions()) ?> recommended <?= count($done->actions()) === 1 ? 'change' : 'changes' ?></h3></div><a class="button button-small button-ghost" href="<?= $view->e($base . '/opportunities') ?>">Open opportunities</a></div>
    <p class="muted small">Each action is added to Opportunities at the next analysis, ranked and tracked with your other findings.</p>
    <?php if ($done->actions() === []): ?><p class="muted">No change stands out. <?= $done->bottleneck() === 'unclear' ? 'The competing pages likely win on links or domain authority; look at Backlinks.' : '' ?></p><?php endif; ?>
    <ol class="data-quality-checks">
    <?php foreach ($done->actions() as $index => $action): ?><?php $text = $done->actionText($index); ?>
        <li><strong><?= $view->e($action['title']) ?></strong> <span class="badge"><?= $view->e($action['effort']) ?> effort</span><br><span class="small"><?= $view->e($text !== null ? $text['what_to_do'] : $action['what']) ?></span><?php if ($text !== null && $text['impact_rationale'] !== ''): ?><br><span class="muted small">Why: <?= $view->e($text['impact_rationale']) ?></span><?php endif; ?><?php if ($action['evidence'] !== []): ?><br><span class="muted small">Seen on: <?= $view->e(implode(', ', array_map($path, $action['evidence']))) ?></span><?php endif; ?>
            <?php if ($text !== null && $text['outline'] !== []): ?><details class="small"><summary>Suggested outline</summary><ul><?php foreach ($text['outline'] as $heading): ?><li><?= $view->e($heading) ?></li><?php endforeach; ?></ul></details><?php endif; ?></li>
    <?php endforeach; ?>
    </ol>
</section>

<?php $plan = $explanation !== null && is_array($explanation['page_plan'] ?? null) ? $explanation['page_plan'] : []; ?>
<?php if ($plan !== []): ?>
<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Page plan</p><h3>Pages this topic needs</h3></div></div>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Role</th><th scope="col">Page type</th><th scope="col">Page</th><th scope="col">Searches</th></tr></thead><tbody>
    <?php foreach ($plan as $row): ?><tr><td><?= $view->e($roleLabel[(string) $row['role']] ?? (string) $row['role']) ?></td><td><?= $view->e(ResultsFormat::label((string) $row['page_type'])) ?></td><td class="url-cell"><?= $view->e(is_string($row['url'] ?? null) ? $path($row['url']) : 'Create') ?></td><td class="small"><?= $view->e(implode(', ', (array) ($row['keywords'] ?? []))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>

<?php if ($linkPlan !== null): ?>
<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Internal links</p><h3><?= count($links) ?> recommended <?= count($links) === 1 ? 'link' : 'links' ?></h3></div></div>
    <?php if (is_string($linkPlan['note'] ?? null)): ?><p class="muted small"><?= $view->e($linkPlan['note']) ?><?php if (($linkPlan['status'] ?? null) === 'no_audit'): ?> <a href="<?= $view->e($base . '/site-audit') ?>">Open Site audit</a><?php endif; ?></p><?php endif; ?>
    <?php $roles = is_array($linkPlan['roles'] ?? null) ? $linkPlan['roles'] : []; ?>
    <?php if ($links !== []): ?>
        <?php
        $nodes = array_map('strval', array_keys($roles));
        foreach ($links as $l) {
            foreach ([$l['source'], $l['target']] as $u) {
                if (!in_array($u, $nodes, true)) {
                    $nodes[] = $u;
                }
            }
        }
        $nodes = array_slice($nodes, 0, 9);
        $center = array_search('money', $roles, true);
        $center = is_string($center) ? $center : $links[0]['target'];
        $others = array_values(array_filter($nodes, static fn(string $u): bool => $u !== $center));
        $ry = count($others) > 2 ? 70 : 0;
        $pos = [$center => [300.0, $ry + 20.0]];
        foreach ($others as $i => $u) {
            $angle = M_PI + 2 * M_PI * $i / max(1, count($others));
            $pos[$u] = [300 + 210 * cos($angle), $ry + 20 + $ry * sin($angle)];
        }
        ?>
        <svg class="comparison-graph" viewBox="0 0 600 <?= 2 * $ry + 50 ?>" role="img" aria-label="Topic pages; recommended links are dashed">
            <?php foreach ($links as $l): ?><?php if (isset($pos[$l['source']], $pos[$l['target']])): ?><line x1="<?= round($pos[$l['source']][0]) ?>" y1="<?= round($pos[$l['source']][1]) ?>" x2="<?= round($pos[$l['target']][0]) ?>" y2="<?= round($pos[$l['target']][1]) ?>" class="comparison-graph-link"/><?php endif; ?><?php endforeach; ?>
            <?php foreach ($pos as $u => $xy): ?><circle cx="<?= round($xy[0]) ?>" cy="<?= round($xy[1]) ?>" r="<?= $u === $center ? 9 : 6 ?>" class="comparison-graph-node<?= $u === $center ? ' is-main' : '' ?>"/><text x="<?= round($xy[0]) ?>" y="<?= round($xy[1]) + 21 ?>" text-anchor="middle"><?= $view->e(mb_strimwidth($path((string) $u), 0, 34, '…')) ?></text><?php endforeach; ?>
        </svg>
    <ol class="data-quality-checks">
    <?php foreach ($links as $l): ?>
        <li><strong><?= $view->e($path($l['source'])) ?> → <?= $view->e($path($l['target'])) ?></strong><?php if (isset($roles[$l['target']])): ?> <span class="badge"><?= $view->e($roleLabel[(string) $roles[$l['target']]] ?? '') ?></span><?php endif; ?><br>
            <span class="small">Anchors: <?= $view->e(implode(' · ', array_map(static fn(string $a): string => '“' . $a . '”', $l['anchors']))) ?></span><br>
            <span class="small"><?= $view->e($l['placement']) ?></span><br><span class="muted small"><?= $view->e($l['reason']) ?></span></li>
    <?php endforeach; ?>
    </ol>
    <p class="muted small">Each target page becomes one internal-links opportunity listing these sources.</p>
    <?php endif; ?>
    <?php foreach ((array) ($linkPlan['underlinked'] ?? []) as $u): ?><p class="small">⚠ <?= $view->e($path((string) $u['url'])) ?> has only <?= (int) $u['inbound'] ?> internal <?= (int) $u['inbound'] === 1 ? 'link' : 'links' ?> pointing to it.</p><?php endforeach; ?>
    <?php foreach ((array) ($linkPlan['competing'] ?? []) as $pair): ?><p class="small">⚠ <?= $view->e($path((string) $pair[0])) ?> and <?= $view->e($path((string) $pair[1])) ?> compete for the same searches: merge them with a redirect or split the searches clearly; linking them to each other does not fix that.</p><?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($can_run): ?>
<section class="card">
    <form method="post" action="<?= $view->e($base . '/investigations/from-analysis') ?>" class="inline-form"><?= $view->csrfField() ?>
        <input type="hidden" name="analysis_id" value="<?= $view->e($done->publicId) ?>"><input type="hidden" name="investigation" value="new">
        <label for="investigation-title" class="small">Keep this as evidence</label><input id="investigation-title" name="title" maxlength="200" value="<?= $view->e('Why competitors outrank us for “' . $cluster->label . '”') ?>">
        <button type="submit" class="button button-small button-ghost">Start an investigation with it</button>
        <span class="muted small">Adds its sources, checks and limitations. To add it to an existing investigation, open that investigation.</span>
    </form>
</section>
<?php endif; ?>

<?php if ($coverage !== []): ?>
<section class="card">
    <details open><summary>Subtopics covered by same-type competitors (<?= count($coverage) ?>)</summary>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Subtopic</th><th scope="col">Competitors</th><th scope="col">Your page</th></tr></thead><tbody>
    <?php foreach ($coverage as $row): ?><tr><td><?= $view->e((string) $row['topic']) ?></td><td><?= count((array) $row['competitors']) ?></td><td><?= $row['own'] ? '✓' : '–' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></details>
</section>
<?php endif; ?>

<section class="card">
    <details><summary>Signals of the fetched pages</summary>
    <div class="table-scroll"><table class="table table-dense numeric"><thead><tr><th scope="col">Page</th><th scope="col">Status</th><th scope="col">Words</th><th scope="col">Headings</th><th scope="col">Images</th><th scope="col">Schema</th><th scope="col">Updated</th></tr></thead><tbody>
    <?php foreach ($done->pages as $url => $page): ?><tr><td class="url-cell"><?= $view->e($path($url)) ?><?= $url === $done->ownUrl ? ' (you)' : '' ?></td><td><?= $view->e($page->readable() ? 'read' : $page->state) ?></td><td><?= $page->readable() ? $view->e(number_format($page->wordCount)) : '—' ?></td><td><?= count($page->outline) ?></td><td><?= $page->images ?></td><td class="small"><?= $view->e(implode(', ', array_slice($page->schemaTypes, 0, 4))) ?></td><td><?= $view->e((string) ($page->modified ?? $page->published ?? '—')) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></details>
    <p class="muted small">Analyzed <?= $view->e((string) $done->finishedAt) ?> UTC · <?= (int) ($verdict['readable'] ?? 0) ?> of <?= (int) ($verdict['attempted'] ?? 0) ?> competing pages readable · results page <?= $done->serpSource === Analysis::PAID ? 'looked up ($' . $view->e(number_format((float) $done->cost, 3)) . ')' : 'from rank tracking' ?>. Only page structure is stored, never the competitors' text.</p>
</section>
<?php endif; ?>
</div>
