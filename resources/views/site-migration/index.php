<?php

declare(strict_types=1);

/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<SeoControlCenter\Modules\SiteMigration\SiteMigration> $plans
 * @var bool $canEdit
 */

$base = '/projects/' . $project->publicId . '/site-migration';
$view->extend('layouts/app', ['title' => 'Site migration · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-migration']);
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow">Improve · Site migration</p><h2>Move a site without losing its search traffic</h2><p class="muted">Plan the redirects of a domain move or a restructure, test them before and after launch, and see which pages kept their clicks.</p></div></header>

<section class="card" aria-labelledby="migration-plans-heading">
    <h2 id="migration-plans-heading">Migration plans</h2>
    <?php if ($plans === []): ?>
    <div class="empty-state"><h3>No migration plans yet</h3><p>Create one for each move: a new domain, HTTPS, a new shop system or a new URL structure.</p></div>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Plan</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Launch</th><th scope="col">Old addresses</th><th scope="col">Last test</th></tr></thead>
        <tbody>
        <?php foreach ($plans as $plan): ?>
            <tr>
                <td><a href="<?= $view->e($base . '/' . $plan->publicId) ?>"><?= $view->e($plan->name) ?></a></td>
                <td><?= $view->e($plan->oldOrigin) ?></td>
                <td><?= $view->e($plan->newOrigin) ?></td>
                <td><?= $view->e($plan->launchDate ?? 'Not set') ?></td>
                <td><?= $view->e(number_format($plan->urlCount)) ?></td>
                <td><?= $view->e($plan->testedAt ?? 'Not tested') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php if ($canEdit): ?>
<section class="card" aria-labelledby="migration-new-heading">
    <h2 id="migration-new-heading">New migration plan</h2>
    <form method="post" action="<?= $view->e($base) ?>" class="stack">
        <?= $view->csrfField() ?>
        <div class="field"><label for="migration-name">Name</label><input id="migration-name" name="name" required maxlength="120" placeholder="Move to the new shop"></div>
        <div class="field"><label for="migration-old">Old site</label><input id="migration-old" name="old_origin" required maxlength="255" value="<?= $view->e('https://' . $project->primaryDomain) ?>"><p class="muted small">The address the pages are on today, such as https://www.example.com.</p></div>
        <div class="field"><label for="migration-new">New site</label><input id="migration-new" name="new_origin" required maxlength="255" placeholder="https://www.example.com"><p class="muted small">Use the same address as the old site for a restructure on the same domain. The old or the new site must be on <?= $view->e($project->primaryDomain) ?>.</p></div>
        <div class="field"><label for="migration-launch">Launch date</label><input id="migration-launch" name="launch_date" type="date"><p class="muted small">Optional. After launch, the plan compares each page’s Search Console clicks before and after.</p></div>
        <button class="button button-primary">Create plan</button>
    </form>
</section>
<?php endif; ?>
</div>
