<?php

declare(strict_types=1);

/**
 * Research on file (ResearchOnFile::list): the paid lookups and analyses
 * a project already has, with their inputs and freshness.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<array{kind: string, label: string, subject: ?string, market: ?string, inputs: ?string, status: string, requested_at: string, fetched_at: ?string, age_days: ?int, fresh: bool, rows: int}> $lookups
 * @var list<array{analysis_id: string, topic: string, keyword: string, results_page: string, finished_at: ?string, age_days: ?int, fresh: bool, ai: string}> $analyses
 * @var array{count: int, newest: ?string, oldest: ?string} $results_pages
 * @var int $fresh_days
 */

$base = '/projects/' . $project->publicId;
$view->extend('layouts/app', ['title' => 'Research on file · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'keywords']);
$age = static fn(?int $days): string => $days === null ? '—' : ($days === 0 ? 'today' : ($days === 1 ? '1 day ago' : $days . ' days ago'));
$freshCount = count(array_filter($lookups, static fn(array $l): bool => $l['fresh']));
?>
<div class="research-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · On file</p><h2>Research on file</h2><p class="muted">Every paid lookup and analysis this project already has. Lookups stay current for <?= (int) $fresh_days ?> days; SEOcc asks before buying the same lookup again within that time.</p></div><nav class="research-shortcuts" aria-label="Research"><a href="<?= $view->e($base . '/keywords/research') ?>">Keyword research</a><a href="<?= $view->e($base . '/keywords/topics') ?>">Topics & owners</a><a href="<?= $view->e($base . '/data-quality') ?>">Data quality</a></nav></header>

<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Paid lookups</p><h3><?= count($lookups) ?> on file · <?= $freshCount ?> current</h3></div></div>
    <?php if ($lookups === []): ?>
        <div class="empty-state"><h3>No paid lookups yet</h3><p>Keyword research, competitor, AI visibility and backlink lookups appear here once they have run.</p></div>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Lookup</th><th scope="col">Market</th><th scope="col">Inputs</th><th scope="col">Ran</th><th scope="col">State</th><th scope="col" class="numeric">Rows</th></tr></thead><tbody>
    <?php foreach ($lookups as $l): ?>
        <tr><td><?= $view->e($l['label']) ?><?= $l['subject'] !== null ? '<br><span class="muted small">' . $view->e($l['subject']) . '</span>' : '' ?></td>
            <td class="small"><?= $view->e($l['market'] ?? '—') ?></td>
            <td class="small"><?= $view->e($l['inputs'] ?? '—') ?></td>
            <td class="small"><?= $view->e($l['fetched_at'] !== null ? substr($l['fetched_at'], 0, 10) . ' · ' . $age($l['age_days']) : 'requested ' . substr($l['requested_at'], 0, 10)) ?></td>
            <td><span class="badge <?= $l['fresh'] ? 'badge-ok' : ($l['status'] === 'done' ? '' : 'badge-warning') ?>"><?= $view->e($l['fresh'] ? 'current' : ($l['status'] === 'done' ? 'older' : $l['status'])) ?></span></td>
            <td class="numeric"><?= $view->e(number_format($l['rows'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Why they outrank you</p><h3><?= count($analyses) ?> analyzed <?= count($analyses) === 1 ? 'topic' : 'topics' ?></h3></div></div>
    <?php if ($analyses === []): ?><p class="muted">No topic has been analyzed in the last year. Start one from <a href="<?= $view->e($base . '/keywords/topics') ?>">Topics & owners</a>.</p><?php else: ?>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Topic</th><th scope="col">Results page</th><th scope="col">AI explanation</th><th scope="col">Analyzed</th><th scope="col">State</th></tr></thead><tbody>
    <?php foreach ($analyses as $a): ?>
        <tr><td><?= $view->e($a['topic']) ?><br><span class="muted small"><?= $view->e($a['keyword']) ?></span></td><td class="small"><?= $view->e($a['results_page']) ?></td><td class="small"><?= $view->e($a['ai']) ?></td><td class="small"><?= $view->e(substr((string) $a['finished_at'], 0, 10) . ' · ' . $age($a['age_days'])) ?></td><td><span class="badge <?= $a['fresh'] ? 'badge-ok' : '' ?>"><?= $a['fresh'] ? 'current' : 'older' ?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Stored results pages</p><h3><?= (int) $results_pages['count'] ?> tracked <?= $results_pages['count'] === 1 ? 'search' : 'searches' ?></h3></div></div>
    <p class="muted small"><?= $results_pages['count'] === 0 ? 'Rank tracking stores the top 10 results of each tracked keyword; none are stored yet.' : $view->e(sprintf('From rank tracking, checked between %s and %s. Analyses of topics that contain these searches use them for free.', (string) $results_pages['oldest'], (string) $results_pages['newest'])) ?></p>
</section>
</div>
