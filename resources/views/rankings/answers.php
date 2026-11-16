<?php

declare(strict_types=1);

/**
 * Featured snippets and "People also ask" questions of the tracked keywords (1.5.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Rankings\RankingSettings $settings
 * @var string $domain
 * @var list<SeoControlCenter\Domain\Rankings\SerpAnswers> $snippets
 * @var list<SeoControlCenter\Domain\Rankings\SerpAnswers> $questions
 * @var int $opportunities
 * @var int $held
 */

$base = '/projects/' . $project->publicId . '/rankings';
$view->extend('layouts/app', ['title' => 'Snippets & questions · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Rankings'], [null, 'Snippets & questions']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'rankings']);
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Rankings</a></p><h2>Featured snippets and “People also ask”</h2><p class="muted">From the latest <?= $view->e($settings->device->value) ?> rank check of each keyword. Nothing is checked live on this page.</p></div></header>

<?php if ($snippets === [] && $questions === []): ?>
<section class="card"><p>No featured snippets or “People also ask” questions yet. They are recorded from SEOcc 1.5.0 on, with each rank check. <a href="<?= $view->e($base) ?>">Back to rankings</a></p></section>
<?php else: ?>
<section class="card" aria-labelledby="snippets-heading">
    <h2 id="snippets-heading">Featured snippets</h2>
    <p class="muted"><?= $view->e(number_format($opportunities)) ?> within reach (another site holds the snippet and you rank in the top <?= $view->e((string) SeoControlCenter\Domain\Rankings\SerpAnswers::SNIPPET_REACH) ?>) · <?= $view->e(number_format($held)) ?> held by <?= $view->e($domain) ?></p>
    <?php if ($snippets === []): ?>
    <p>None of the tracked keywords showed a featured snippet in its latest check.</p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Keyword</th><th scope="col">Snippet held by</th><th scope="col">Your position</th><th scope="col">Your ranking page</th><th scope="col">Checked</th></tr></thead>
        <tbody>
        <?php foreach ($snippets as $row): ?>
            <tr>
                <td><a href="<?= $view->e($base . '/' . $row->keywordPublicId) ?>"><?= $view->e($row->keyword) ?></a><?php if ($row->isSnippetOpportunity($domain)): ?> <span class="badge badge-warning">Within reach</span><?php elseif ($row->holdsSnippet($domain)): ?> <span class="badge badge-ok">Yours</span><?php endif; ?></td>
                <td><?= $row->snippetDomain !== null ? $view->e($row->snippetDomain) : '<span class="muted">Not recorded (check before 1.5.0)</span>' ?></td>
                <td><?= $row->position !== null ? $view->e((string) $row->position) : '<span class="muted">&gt;100</span>' ?></td>
                <td><?= $row->url !== null ? '<a href="' . $view->e($row->url) . '" rel="noopener noreferrer" target="_blank">' . $view->e((string) (parse_url($row->url, PHP_URL_PATH) ?: '/')) . '</a>' : '<span class="muted">–</span>' ?></td>
                <td><?= $view->e($row->checkedOn) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">To win a snippet, answer the question in the first paragraph under a heading that matches the search, in 40–60 words, or as a short list or table when the snippet shows one.</p>
    <?php endif; ?>
</section>

<section class="card" aria-labelledby="questions-heading">
    <h2 id="questions-heading">“People also ask” questions</h2>
    <?php if ($questions === []): ?>
    <p>No questions recorded yet.</p>
    <?php else: ?>
    <p class="muted">Questions Google shows for your keywords. Answering them on the ranking page, each under its own heading, can win these boxes and widen the page's reach.</p>
    <?php foreach ($questions as $row): ?>
    <details class="stack">
        <summary><strong><?= $view->e($row->keyword) ?></strong> · <?= $view->e((string) count($row->questions)) ?> questions<?= $row->position !== null ? ' · position ' . $view->e((string) $row->position) : '' ?></summary>
        <ul><?php foreach ($row->questions as $question): ?><li><?= $view->e($question) ?></li><?php endforeach; ?></ul>
    </details>
    <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
