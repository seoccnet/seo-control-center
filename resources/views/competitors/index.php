<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Rankings\RankingSettings $settings
 * @var SeoControlCenter\Domain\Rankings\RankingDay|null $latest
 * @var SeoControlCenter\Domain\Rankings\RankingDay|null $compare
 * @var list<array{competitor: SeoControlCenter\Domain\Competitors\Competitor, day: ?SeoControlCenter\Domain\Rankings\RankingDay, compare: ?SeoControlCenter\Domain\Rankings\RankingDay, overlap: int, gaps: int, behind: int}> $rows
 * @var list<SeoControlCenter\Domain\Competitors\CompetitorCandidate> $candidates
 * @var bool $canEdit
 * @var array<string, string> $errors
 * @var string $domain
 * @var int $maxCompetitors
 */
$view->extend('layouts/app', ['title' => 'Competitors · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [null, 'Competitors']]]);
$base = '/projects/' . $project->publicId;
$visibility = static fn(?SeoControlCenter\Domain\Rankings\RankingDay $d): string => $d === null ? '—' : number_format($d->visibility, 1) . ' %';
$change = static function (?SeoControlCenter\Domain\Rankings\RankingDay $now, ?SeoControlCenter\Domain\Rankings\RankingDay $before) use ($view): string {
    if ($now === null || $before === null) {
        return '—';
    }
    $diff = $now->visibility - $before->visibility;
    if (abs($diff) < 0.05) {
        return '<span class="delta">no change</span>';
    }

    return sprintf('<span class="delta %s">%s %s</span>', $diff > 0 ? 'delta-good' : 'delta-bad', $diff > 0 ? '▲' : '▼', $view->e(sprintf('%+.1f pts', $diff)));
};
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'competitors']) ?>
<div class="research-workspace research-competitors">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Competitors</p><h2>Your competitive landscape</h2><p class="muted">Compare tracked-keyword visibility, find gaps and explore competing domains.</p></div><nav class="research-shortcuts" aria-label="Research sections"><a href="#research-competitor-comparison">Visibility comparison</a><?php if ($canEdit && $candidates !== []): ?><a href="#research-competitor-suggestions">Suggested domains</a><?php endif; ?></nav></header>

<?php if ($latest === null): ?>
    <section class="card">
        <p>Competitors are measured on the Google results of your <a href="<?= $view->e($base) ?>/rankings">rank checks</a>, so they cost nothing extra.
            <?= $settings->enabled ? 'Results appear after the first check.' : 'Switch rank tracking on to start measuring.' ?></p>
    </section>
<?php endif; ?>

<section class="card" id="research-competitor-comparison">
    <h2>Comparison</h2>
    <?php if ($latest !== null): ?>
        <p class="muted small">Check of <?= $view->e($latest->date) ?> (UTC, Google <?= $view->e($settings->device->value) ?>)<?= $compare !== null ? ', change since ' . $view->e($compare->date) : '' ?>. Only your tracked keywords are compared.</p>
    <?php endif; ?>
    <div class="table-scroll">
        <table class="table table-dense numeric">
            <thead>
                <tr>
                    <th scope="col">Domain</th>
                    <th scope="col">Visibility</th>
                    <th scope="col">Change</th>
                    <th scope="col">Avg. position</th>
                    <th scope="col">Top 3</th>
                    <th scope="col">Top 10</th>
                    <th scope="col">Both rank</th>
                    <th scope="col">Ahead of you</th>
                    <th scope="col">Gaps</th>
                    <?php if ($canEdit): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?= $view->e($project->primaryDomain) ?></strong> <span class="muted small">you</span></td>
                    <td><?= $view->e($visibility($latest)) ?></td>
                    <td><?= $change($latest, $compare) ?></td>
                    <td><?= $latest?->averagePosition !== null ? $view->e(number_format($latest->averagePosition, 1)) : '—' ?></td>
                    <td><?= $latest !== null ? $view->e($latest->top3) : '—' ?></td>
                    <td><?= $latest !== null ? $view->e($latest->top10) : '—' ?></td>
                    <td>—</td><td>—</td><td>—</td>
                    <?php if ($canEdit): ?><td></td><?php endif; ?>
                </tr>
                <?php foreach ($rows as $row): ?>
                    <?php $c = $row['competitor']; $d = $row['day']; ?>
                    <tr>
                        <td><a href="<?= $view->e($base) ?>/competitors/<?= $view->e($c->publicId) ?>"><?= $view->e($c->domain) ?></a></td>
                        <td><?= $view->e($visibility($d)) ?></td>
                        <td><?= $change($d, $row['compare']) ?></td>
                        <td><?= $d?->averagePosition !== null ? $view->e(number_format($d->averagePosition, 1)) : '—' ?></td>
                        <td><?= $d !== null ? $view->e($d->top3) : '—' ?></td>
                        <td><?= $d !== null ? $view->e($d->top10) : '—' ?></td>
                        <td><?= $view->e($row['overlap']) ?></td>
                        <td><?= $view->e($row['behind']) ?></td>
                        <td><?= $row['gaps'] > 0 ? '<a href="' . $view->e($base . '/competitors/' . $c->publicId . '?view=gaps') . '">' . $view->e($row['gaps']) . '</a>' : '0' ?></td>
                        <?php if ($canEdit): ?>
                            <td>
                                <form method="post" action="<?= $view->e($base) ?>/competitors/<?= $view->e($c->publicId) ?>/delete" class="inline-form">
                                    <?= $view->csrfField() ?>
                                    <button type="submit" class="button button-small button-ghost" aria-label="Remove <?= $view->e($c->domain) ?>">Remove</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted small">Gaps: keywords where the competitor is on page one and at least 10 places ahead of you (or you are not in the top 100).</p>
</section>

<?php if ($canEdit): ?>
    <section class="card">
        <h2>Add a competitor</h2>
        <?php if (count($rows) >= $maxCompetitors): ?>
            <p class="muted">This project has the maximum of <?= $view->e($maxCompetitors) ?> competitors.</p>
        <?php else: ?>
            <form method="post" action="<?= $view->e($base) ?>/competitors" class="form" novalidate>
                <?= $view->csrfField() ?>
                <div class="field<?= isset($errors['domain']) ? ' has-error' : '' ?>">
                    <label for="domain">Domain</label>
                    <input id="domain" name="domain" type="text" required maxlength="253" placeholder="competitor.com" value="<?= $view->e($domain) ?>" autocomplete="off"<?= $view->invalid($errors, 'domain', 'domain') ?>>
                    <?= $view->fieldError($errors, 'domain', 'domain') ?>
                    <p class="muted small">Subdomains count as the competitor's results. Existing checks are used right away (from the stored top 10); every new check records the full top 100.</p>
                </div>
                <button type="submit" class="button">Add competitor</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($candidates !== []): ?>
        <section class="card" id="research-competitor-suggestions">
            <h2>Suggested competitors</h2>
            <p class="muted small">Domains that rank in the top 10 for your keywords most often (check of <?= $view->e($latest->date ?? '') ?>).</p>
            <div class="table-scroll">
                <table class="table table-dense numeric">
                    <thead><tr><th scope="col">Domain</th><th scope="col">Keywords in top 10</th><th scope="col">Avg. position</th><th scope="col">Top 3</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                        <?php foreach ($candidates as $candidate): ?>
                            <tr>
                                <td><?= $view->e($candidate->domain) ?></td>
                                <td><?= $view->e($candidate->keywords) ?></td>
                                <td><?= $view->e(number_format($candidate->averagePosition, 1)) ?></td>
                                <td><?= $view->e($candidate->top3) ?></td>
                                <td>
                                    <form method="post" action="<?= $view->e($base) ?>/competitors" class="inline-form">
                                        <?= $view->csrfField() ?>
                                        <input type="hidden" name="domain" value="<?= $view->e($candidate->domain) ?>">
                                        <input type="hidden" name="source" value="discovered">
                                        <button type="submit" class="button button-small">Add</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

</div>
