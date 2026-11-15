<?php

declare(strict_types=1);

use SeoControlCenter\Domain\SiteMigration\RedirectCheck;
use SeoControlCenter\Modules\SiteMigration\SiteMigration;

/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SiteMigration $plan
 * @var list<SeoControlCenter\Modules\SiteMigration\MigrationUrl> $rows
 * @var int $total
 * @var array<string, int> $counts
 * @var ?string $filter
 * @var int $page
 * @var int $pageCount
 * @var array{before: SeoControlCenter\Domain\Shared\DateRange, after: SeoControlCenter\Domain\Shared\DateRange, beforeClicks: int, afterClicks: int, rows: list<array{url: SeoControlCenter\Modules\SiteMigration\MigrationUrl, before: int, after: int}>, waiting: ?string}|null $results
 * @var bool $canEdit
 * @var bool $canTest
 * @var int $maxUrls
 * @var int $maxTargets
 */

$base = '/projects/' . $project->publicId . '/site-migration/' . $plan->publicId;
$view->extend('layouts/app', ['title' => $plan->name . ' · Site migration · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-migration']);
$problems = array_sum(array_intersect_key($counts, array_flip(RedirectCheck::PROBLEMS)));
$tested = array_sum($counts) - ($counts[RedirectCheck::UNTESTED] ?? 0);
$stateText = static fn(string $state): string => match ($state) {
    SiteMigration::QUEUED => 'Queued',
    SiteMigration::RUNNING => 'Running',
    SiteMigration::DONE => 'Done',
    SiteMigration::FAILED => 'Failed',
    default => 'Not run',
};
$link = static fn(?string $f, int $p = 1): string => $base . '?' . http_build_query(array_filter(['filter' => $f, 'page' => $p > 1 ? $p : null])) . '#plan-redirects';
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="/projects/<?= $view->e($project->publicId) ?>/site-migration">Site migration</a></p><h2><?= $view->e($plan->name) ?></h2><p class="muted"><?= $view->e($plan->oldOrigin) ?> → <?= $view->e($plan->newOrigin) ?><?= $plan->launchDate !== null ? ' · launch ' . $view->e($plan->launchDate) : '' ?></p></div>
<nav class="improve-shortcuts" aria-label="Plan sections"><a href="#plan-addresses">Addresses</a><a href="#plan-redirects">Redirects</a><a href="#plan-results">Results</a><a href="#plan-settings">Settings</a></nav></header>

<section class="card" aria-label="Plan status">
    <dl class="indexing-evidence">
        <dt>Old addresses</dt><dd><?= $view->e(number_format($plan->urlCount)) ?> of up to <?= $view->e(number_format($maxUrls)) ?></dd>
        <dt>New site’s addresses</dt><dd><?= $view->e(number_format($plan->targetCount)) ?></dd>
        <dt>Redirect suggestions</dt><dd><?= $view->e($stateText($plan->matchState)) ?></dd>
        <dt>Redirect test</dt><dd><?= $view->e($stateText($plan->testState)) ?><?= $plan->testedAt !== null ? ' · finished ' . $view->e($plan->testedAt) . ' UTC' : '' ?><?= $tested > 0 ? ' · ' . $view->e(number_format($tested)) . ' tested, ' . $view->e(number_format($problems)) . ' to fix' : '' ?></dd>
    </dl>
    <?php if ($plan->error !== null): ?><p role="status"><?= $view->e($plan->error) ?></p><?php endif; ?>
</section>

<?php if ($canEdit): ?>
<section class="card" id="plan-addresses" aria-labelledby="plan-addresses-heading">
    <h2 id="plan-addresses-heading">1. Addresses</h2>
    <p class="muted">Old addresses are the pages that must keep working: take them from Search Console (every page with impressions in the last 16 months), the latest site audit, or a list such as an export of the old shop. New addresses are the pages of the new site, which suggestions are matched against.</p>
    <div class="migration-columns">
        <form method="post" action="<?= $view->e($base . '/old') ?>" class="stack">
            <?= $view->csrfField() ?>
            <h3>Old addresses on <?= $view->e($plan->oldOrigin) ?></h3>
            <fieldset><legend class="small">Source</legend>
                <label class="small"><input type="radio" name="source" value="search_console" checked> Search Console pages</label>
                <label class="small"><input type="radio" name="source" value="site_audit"> Latest site audit</label>
                <label class="small"><input type="radio" name="source" value="paste"> The list below</label>
            </fieldset>
            <div class="field"><label for="old-urls">Addresses or paths, one per line</label><textarea id="old-urls" name="urls" rows="5" maxlength="4000000" placeholder="/old-category/product-a&#10;<?= $view->e($plan->oldOrigin) ?>/about-us.html"></textarea><p class="muted small">A CSV pastes too: the first column is read. Imports add to the plan.</p></div>
            <button class="button button-small">Add old addresses</button>
        </form>
        <form method="post" action="<?= $view->e($base . '/new') ?>" class="stack">
            <?= $view->csrfField() ?>
            <h3>New addresses on <?= $view->e($plan->newOrigin) ?></h3>
            <fieldset><legend class="small">Source</legend>
                <label class="small"><input type="radio" name="source" value="paste" checked> The list below</label>
                <label class="small"><input type="radio" name="source" value="site_audit"> Latest site audit (when the new site is already live)</label>
            </fieldset>
            <div class="field"><label for="new-urls">Addresses or paths, one per line</label><textarea id="new-urls" name="urls" rows="5" maxlength="4000000" placeholder="/category/product-a&#10;/about"></textarea><p class="muted small">Up to <?= $view->e(number_format($maxTargets)) ?>. Saving replaces the previous list. A staging site’s sitemap or URL export works well.</p></div>
            <button class="button button-small">Save new addresses</button>
        </form>
    </div>
</section>
<?php endif; ?>

<section class="card" id="plan-redirects" aria-labelledby="plan-redirects-heading">
    <div class="sync-row"><div><h2 id="plan-redirects-heading">2. Redirects</h2><p class="muted">Each old address with the new address it should redirect to. Suggestions match the same path, the same last segment, the most similar path, or the closest parent folder; old addresses with no candidate stay empty rather than pointing at the home page, which search engines treat as a missing page.</p></div></div>
    <div class="inline-form">
        <?php if ($canEdit): ?><form method="post" action="<?= $view->e($base . '/suggest') ?>"><?= $view->csrfField() ?><button class="button button-small"<?= $plan->busy() ? ' disabled' : '' ?>>Suggest redirects</button></form><?php endif; ?>
        <?php if ($canTest): ?><form method="post" action="<?= $view->e($base . '/test') ?>"><?= $view->csrfField() ?><button class="button button-small"<?= $plan->busy() ? ' disabled' : '' ?>>Test redirects</button></form><?php endif; ?>
        <a class="button button-small button-ghost" href="<?= $view->e($base . '/export?format=csv') ?>">CSV</a>
        <a class="button button-small button-ghost" href="<?= $view->e($base . '/export?format=apache') ?>">Apache .htaccess</a>
        <a class="button button-small button-ghost" href="<?= $view->e($base . '/export?format=nginx') ?>">nginx</a>
    </div>
    <p class="muted small">The test requests every old address once (HTTPS only, robots.txt respected, redirects not followed) and checks that it answers 301 or 308 with the chosen address, and that this address loads. Before launch most old addresses still answer themselves; run it again on launch day.</p>
    <nav class="module-tabs" aria-label="Filter redirects">
        <a href="<?= $view->e($link(null)) ?>"<?= $filter === null ? ' aria-current="page"' : '' ?>>All</a>
        <a href="<?= $view->e($link('problems')) ?>"<?= $filter === 'problems' ? ' aria-current="page"' : '' ?>>To fix (<?= $view->e(number_format($problems)) ?>)</a>
        <a href="<?= $view->e($link('unmatched')) ?>"<?= $filter === 'unmatched' ? ' aria-current="page"' : '' ?>>No target</a>
        <a href="<?= $view->e($link(RedirectCheck::OK)) ?>"<?= $filter === RedirectCheck::OK ? ' aria-current="page"' : '' ?>>Working (<?= $view->e(number_format($counts[RedirectCheck::OK] ?? 0)) ?>)</a>
    </nav>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls>
        <thead><tr><th scope="col">Old address</th><th scope="col">Clicks, 28 days</th><th scope="col">Redirect to</th><th scope="col">Match</th><th scope="col">Test</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="brief-url"><?= $view->e($row->oldUrl) ?></td>
                <td><?= $row->oldClicks !== null ? $view->e(number_format($row->oldClicks)) : '<span class="muted">—</span>' ?></td>
                <td>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= $view->e($base . '/target') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="key" value="<?= $view->e($row->key) ?>"><label class="visually-hidden" for="target-<?= $view->e($row->key) ?>">Redirect target for <?= $view->e($row->oldUrl) ?></label><input id="target-<?= $view->e($row->key) ?>" name="target" maxlength="2048" value="<?= $view->e($row->targetUrl ?? '') ?>" placeholder="No target"><button class="button button-small button-ghost">Save</button></form>
                    <?php else: ?><?= $view->e($row->targetUrl ?? '—') ?><?php endif; ?>
                </td>
                <td><?= $view->e($row->manual ? 'Chosen by hand' : ($row->method !== null ? ucfirst($row->method) . ' · ' . $row->confidence . '%' : '—')) ?></td>
                <td><?= $view->e(RedirectCheck::label($row->testState)) ?><?php if ($row->testDetail !== null): ?><br><span class="muted small"><?= $view->e($row->testDetail) ?></span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="5"><?= $filter !== null ? 'No addresses in this view.' : 'No old addresses yet. Add them above.' ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <nav class="pagination" aria-label="Redirects pagination">
        <?php if ($page > 1): ?><a class="button button-small button-ghost" href="<?= $view->e($link($filter, $page - 1)) ?>">Previous</a><?php endif; ?>
        <span>Page <?= $view->e($page) ?> of <?= $view->e($pageCount) ?> · <?= $view->e(number_format($total)) ?> addresses</span>
        <?php if ($page < $pageCount): ?><a class="button button-small button-ghost" href="<?= $view->e($link($filter, $page + 1)) ?>">Next</a><?php endif; ?>
    </nav>
</section>

<section class="card" id="plan-results" aria-labelledby="plan-results-heading">
    <h2 id="plan-results-heading">3. Results after launch</h2>
    <?php if ($results === null): ?>
    <p class="muted">Set a launch date to compare each old page’s Search Console clicks before launch with its new address after launch.</p>
    <?php elseif ($results['waiting'] !== null): ?>
    <p class="muted"><?= $view->e($results['waiting']) ?></p>
    <?php else: ?>
    <p>Old addresses had <strong><?= $view->e(number_format($results['beforeClicks'])) ?></strong> clicks in the 28 days before launch (<?= $view->e($results['before']->fromString()) ?> to <?= $view->e($results['before']->toString()) ?>). Their new addresses have <strong><?= $view->e(number_format($results['afterClicks'])) ?></strong> per 28 days since launch (<?= $view->e($results['after']->fromString()) ?> to <?= $view->e($results['after']->toString()) ?>, scaled to 28 days).</p>
    <p class="muted small">Some change is normal in the first weeks while search engines process the redirects. Seasonality, other changes and Search Console’s data delay affect both numbers, so read a lost page as a reason to check its redirect and content, not as proof.</p>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls>
        <thead><tr><th scope="col">Old address</th><th scope="col">New address</th><th scope="col">Clicks before</th><th scope="col">Clicks after (per 28 days)</th></tr></thead>
        <tbody>
        <?php foreach ($results['rows'] as $r): ?>
            <tr><td class="brief-url"><?= $view->e($r['url']->oldUrl) ?></td><td class="brief-url"><?= $view->e($r['url']->targetUrl ?? 'No target') ?></td><td><?= $view->e(number_format($r['before'])) ?></td><td><?= $view->e(number_format($r['after'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($results['rows'] === []): ?><tr><td colspan="4">No old address had clicks before launch.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php if ($canEdit): ?>
<section class="card" id="plan-settings" aria-labelledby="plan-settings-heading">
    <h2 id="plan-settings-heading">Settings</h2>
    <form method="post" action="<?= $view->e($base) ?>" class="stack">
        <?= $view->csrfField() ?>
        <div class="field"><label for="plan-name">Name</label><input id="plan-name" name="name" required maxlength="120" value="<?= $view->e($plan->name) ?>"></div>
        <div class="field"><label for="plan-old">Old site</label><input id="plan-old" name="old_origin" required maxlength="255" value="<?= $view->e($plan->oldOrigin) ?>"></div>
        <div class="field"><label for="plan-new">New site</label><input id="plan-new" name="new_origin" required maxlength="255" value="<?= $view->e($plan->newOrigin) ?>"></div>
        <div class="field"><label for="plan-launch">Launch date</label><input id="plan-launch" name="launch_date" type="date" value="<?= $view->e($plan->launchDate ?? '') ?>"></div>
        <button class="button button-small">Save settings</button>
    </form>
    <form method="post" action="<?= $view->e($base . '/delete') ?>" class="inline-form"><?= $view->csrfField() ?><button class="button button-small button-danger" data-confirm="Delete this migration plan and its redirects?">Delete plan</button></form>
</section>
<?php endif; ?>
</div>
