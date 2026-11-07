<?php

declare(strict_types=1);

use SeoControlCenter\Modules\ContentBriefs\BriefInput;

/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{items: list<SeoControlCenter\Modules\ContentBriefs\ContentBrief>, total: int, page: int, pages: int, status: string, search: string} $listing
 * @var array<string, array{queue_days: int, window: ?SeoControlCenter\Modules\Annotations\ImpactWindow}> $progress
 * @var array<string, int> $counts
 */
$base = '/projects/' . $project->publicId . '/content-briefs';
$view->extend('layouts/app', ['title' => 'Content briefs · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'content-briefs']);
$statusNotes = ['draft' => 'Being planned', 'in_progress' => 'Being written or edited', 'published' => 'Live; results are forming'];
?>
<div class="improve-workspace improve-briefs">
<header class="improve-intro"><div><p class="portfolio-eyebrow">Improve · Content briefs</p><h2>Plan your next content improvement</h2><p class="muted">Turn saved evidence into writing plans and follow each brief through publication and the weeks after.</p></div><nav class="improve-shortcuts" aria-label="Improve sections"><a href="#improve-writing-queue">Writing queue</a><?php if ($canEdit): ?><a href="<?= $view->e($base . '/new') ?>">Create content brief</a><?php endif; ?></nav></header>
<section class="brief-stats" aria-label="Briefs by status">
    <?php foreach (BriefInput::STATUSES as $key => $label): ?>
        <a class="brief-stat brief-state-<?= $view->e($key) ?>"<?= $listing['status'] === $key ? ' aria-current="true"' : '' ?> href="<?= $view->e($base . '?' . http_build_query(['status' => $key])) ?>"><span class="brief-stat-label"><?= $view->e($label) ?></span><strong><?= number_format($counts[$key] ?? 0) ?></strong><span class="muted small"><?= $view->e($statusNotes[$key]) ?></span></a>
    <?php endforeach; ?>
</section>
<section class="card">
    <div class="sync-row"><div><h2>Content briefs</h2><p>Turn an SEO opportunity into a clear writing plan, then track the work through publication.</p></div><?php if ($canEdit): ?><a class="button button-primary" href="<?= $view->e($base . '/new') ?>">Create content brief</a><?php endif; ?></div>
    <details><summary>How content briefs work</summary><ol><li>Start from a page, tracked keyword or opportunity, or create a brief for a new page.</li><li>Review the saved evidence and edit intent, headings, snippets and writer instructions.</li><li>Ask AI for suggestions if configured. Its existing assistant costs and budgets apply; saving a brief is free.</li><li>Publish in your CMS, mark the brief Published, and record the actual change in the change timeline. The brief then counts the days until the update usually shows in search data (an updated page about 2–6 weeks, a new page 1–3 months).</li></ol><p>Briefs use local data only. Status changes do not edit your site. Everyone with project access can read briefs, including authorized AI clients; keep secrets and private client details out.</p></details>
</section>
<section class="card" id="improve-writing-queue"><h2>Writing queue</h2>
<form class="brief-filter" method="get" action="<?= $view->e($base) ?>"><div class="field"><label for="brief-search">Search title, keyword or URL</label><input id="brief-search" name="q" maxlength="200" value="<?= $view->e($listing['search']) ?>"></div><div class="field"><label for="brief-status-filter">Status</label><select id="brief-status-filter" name="status"><option value="">All statuses</option><?php foreach (BriefInput::STATUSES as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $listing['status'] === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></div><div class="actions"><button class="button button-secondary">Filter briefs</button><a href="<?= $view->e($base) ?>">Clear</a></div></form>
<p class="muted"><?= $listing['total'] ?> briefs · page <?= $listing['page'] ?> of <?= $listing['pages'] ?></p>
<?php if ($listing['items'] === []): ?><p>No content briefs match this selection. Start from a page or keyword to include its stored evidence.</p><?php endif; ?>
<div class="brief-list"><?php foreach ($listing['items'] as $item): $state = $progress[$item->publicId] ?? ['queue_days' => 0, 'window' => null]; ?>
<article class="brief-list-item brief-state-<?= $view->e($item->input->status) ?>">
    <div class="brief-list-main">
        <p class="brief-list-meta"><span class="brief-status-pill"><?= $view->e(BriefInput::STATUSES[$item->input->status]) ?></span><?php if ($state['window'] === null): ?><span class="brief-queue"><strong><?= $state['queue_days'] ?></strong> <?= $state['queue_days'] === 1 ? 'day' : 'days' ?> in the queue</span><?php else: ?><span class="brief-queue">Published <?= $view->e($state['window']->date) ?> after <?= $state['queue_days'] ?> <?= $state['queue_days'] === 1 ? 'day' : 'days' ?></span><?php endif; ?><span class="muted small">updated <?= $view->e($item->updatedAt) ?> UTC<?php if ($item->via !== null): ?> via <?= $view->e($item->via) ?><?php endif; ?></span></p>
        <h3><a href="<?= $view->e($base . '/' . $item->publicId) ?>"><?= $view->e($item->input->title) ?></a></h3>
        <p><?= $view->e($item->input->keyword) ?></p>
        <p class="brief-url muted"><?= $view->e($item->input->url ?? 'New page — URL not specified') ?></p>
        <?php if ($state['window'] !== null): ?><?= $view->partial('partials/impact-track', ['window' => $state['window'], 'startLabel' => 'Published']) ?><?php endif; ?>
    </div>
    <a class="button button-ghost button-small" href="<?= $view->e($base . '/' . $item->publicId) ?>">Open brief</a>
</article><?php endforeach; ?></div>
<nav class="pagination" aria-label="Content brief pages"><?php foreach (['Previous' => $listing['page'] - 1, 'Next' => $listing['page'] + 1] as $label => $page): ?><?php if ($page >= 1 && $page <= $listing['pages']): ?><a class="button button-ghost" href="<?= $view->e($base . '?' . http_build_query(['page' => $page, 'q' => $listing['search'], 'status' => $listing['status']])) ?>"><?= $view->e($label) ?></a><?php endif; ?><?php endforeach; ?></nav>
</section>

</div>
