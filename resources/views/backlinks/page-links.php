<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\View\Template $view */
$self = '/projects/' . $project->publicId . '/backlinks';
?>
<section class="card improve-link-monitoring" id="backlink-monitoring">
    <h2>Lost-link monitoring</h2>
    <p>Check lost links and referring domains weekly. Off by default; enabling spends your DataForSEO budget. About <?= $view->e('$' . number_format(($costs['bl_lost_links'] ?? 0) + ($costs['bl_lost'] ?? 0), 3)) ?> per weekly check, subject to provider pricing. Strong lost domains can trigger your existing email alerts.</p>
    <?php if ($canEdit && $canRun): ?>
    <form method="post" action="<?= $view->e($self . '/monitoring') ?>">
        <?= $view->csrfField() ?><input type="hidden" name="monitor_lost" value="<?= $monitorLost ? '0' : '1' ?>">
        <button type="submit" class="button button-small"><?= $monitorLost ? 'Stop weekly lost-link checks' : 'Enable weekly paid lost-link checks' ?></button>
    </form>
    <?php endif; ?>
</section>
<?php foreach (['bl_links' => 'Live links by page', 'bl_lost_links' => 'Lost links by page'] as $kind => $label): ?>
<section class="card" id="<?= $view->e($kind) ?>">
    <div class="card-header"><h2><?= $view->e($label) ?></h2><?= $button($kind, 'Fetch ' . ($kind === 'bl_links' ? 'page links' : 'lost page links')) ?></div>
    <p class="muted">A sample of up to 1,000 links<?= $kind === 'bl_lost_links' ? ' lost in the last 30 days' : ', strongest domains first' ?>. Open a target URL to see all its stored evidence.</p>
    <?= $status($results[$kind] ?? null) ?>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Source URL</th><th scope="col">Target URL</th><th scope="col">Anchor</th><th scope="col">Rank</th><th scope="col">Follow</th><th scope="col">Lost on</th></tr></thead><tbody>
    <?php foreach (($results[$kind]?->pageLinks() ?? []) as $link): ?><tr><td><?= $view->e($link->sourceUrl) ?></td><td><a href="<?= $view->e('/projects/' . $project->publicId . '/pages/details?url=' . rawurlencode($link->targetUrl)) ?>"><?= $view->e($link->targetUrl) ?></a></td><td><?= $view->e($link->anchor) ?></td><td><?= $view->e($link->domainRank ?? '—') ?></td><td><?= $link->dofollow ? 'Yes' : 'No' ?></td><td><?= $view->e($link->lostOn ?? '—') ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endforeach; ?>
