<?php

declare(strict_types=1);

/**
 * Research → Topic plans (ADR-041, G2).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<SeoControlCenter\Domain\ArticleIdeas\TopicPlan> $plans
 */

use SeoControlCenter\Domain\ArticleIdeas\TopicPlan;

$view->extend('layouts/app', ['title' => 'Topic plans · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'topic-plans']);
$base = '/projects/' . $project->publicId;
?>
<div class="research-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Topic plans</p><h2>Topic plans</h2><p class="muted">A pillar article with the supporting articles around it, the links between them and from your pages, and what it takes in backlinks to compete. Start a plan from an idea under <a href="<?= $view->e($base . '/article-ideas') ?>">Article ideas</a> with <strong>Plan a topic</strong>.</p></div></header>

<?php if ($plans === []): ?>
<section class="card"><p class="muted">No topic plans yet. Open <a href="<?= $view->e($base . '/article-ideas') ?>">Article ideas</a> and choose <strong>Plan a topic</strong> on the idea that should be the pillar.</p></section>
<?php else: ?>
<section class="card">
    <div class="table-scroll"><table class="table" data-no-table-controls><thead><tr><th scope="col">Pillar</th><th scope="col" class="num">Supporting articles</th><th scope="col" class="num">Searches kept</th><th scope="col" class="num">Your pages</th><th scope="col">Link map</th><th scope="col">Backlinks</th><th scope="col">Updated</th></tr></thead><tbody>
    <?php foreach ($plans as $plan): ?>
        <tr>
            <td><a href="<?= $view->e($base . '/topic-plans/' . $plan->publicId) ?>"><?= $view->e($plan->pillarKeyword) ?></a></td>
            <td class="num"><?= count($plan->kept(TopicPlan::IDEA)) ?></td>
            <td class="num"><?= count($plan->kept(TopicPlan::SEARCH)) ?></td>
            <td class="num"><?= count($plan->kept(TopicPlan::PAGE)) ?></td>
            <td><?= $view->e(match ($plan->linksStatus) { TopicPlan::QUEUED => 'Building…', TopicPlan::DONE => count($plan->links['internal'] ?? []) + count($plan->links['pages'] ?? []) . ' links', default => '—' }) ?></td>
            <td><?= $view->e(match ($plan->backlinksStatus) { TopicPlan::QUEUED => 'Checking…', TopicPlan::DONE => ($plan->referringDomainsToMatch() !== null ? '~' . number_format($plan->referringDomainsToMatch()) . ' referring domains to match' : 'Checked'), TopicPlan::FAILED => 'Failed', default => 'Not checked' }) ?></td>
            <td><?= $view->e(substr($plan->updatedAt, 0, 10)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>
</div>
