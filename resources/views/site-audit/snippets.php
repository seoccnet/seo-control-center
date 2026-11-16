<?php

declare(strict_types=1);

/**
 * Search snippet preview by pixel width (1.6.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var bool $audited
 * @var bool $cutOnly
 * @var int $total
 * @var int $cut
 * @var list<array{url: string, title: ?array{text: string, width: float, limit: int, fits: bool, shown: string}, description: ?array{text: string, width: float, limit: int, fits: bool, shown: string}}> $rows
 * @var array{url: string, title: array{text: string, width: float, limit: int, fits: bool, shown: string}, description: array{text: string, width: float, limit: int, fits: bool, shown: string}}|null $trial
 */

$base = '/projects/' . $project->publicId . '/site-audit';
$view->extend('layouts/app', ['title' => 'Snippet preview · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Site audit'], [null, 'Snippet preview']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-audit']);
$measure = static fn(?array $part): string => $part === null ? 'missing' : number_format($part['width'], 0) . ' / ' . $part['limit'] . ' px';
$serp = static function (string $url, ?array $title, ?array $description) use ($view): string {
    $host = (string) parse_url($url, PHP_URL_HOST);
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

    return '<div class="serp-preview"><p class="serp-url">' . $view->e($host . ($path !== '' ? ' › ' . str_replace('/', ' › ', $path) : '')) . '</p>'
        . '<p class="serp-title">' . $view->e($title['shown'] ?? 'No title: Google writes one') . '</p>'
        . '<p class="serp-description">' . $view->e($description['shown'] ?? 'No description: Google shows text from the page') . '</p></div>';
};
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Site audit</a></p><h2>Snippet preview</h2><p class="muted">How your titles and descriptions fit Google's desktop results. Google cuts by pixel width, not by character count: about <?= $view->e((string) SeoControlCenter\Domain\Snippets\SnippetWidth::TITLE_PX) ?> px for titles and <?= $view->e((string) SeoControlCenter\Domain\Snippets\SnippetWidth::DESCRIPTION_PX) ?> px for descriptions. Google may also rewrite them; this shows what you control.</p></div></header>

<section class="card" aria-labelledby="snippet-try-heading">
    <h2 id="snippet-try-heading">Try a title and description</h2>
    <form method="get" action="<?= $view->e($base . '/snippets') ?>" class="stack">
        <input type="hidden" name="try" value="1">
        <div class="field"><label for="try-url">Page URL</label><input id="try-url" name="url" type="url" value="<?= $view->e($trial['url'] ?? 'https://' . $project->primaryDomain . '/') ?>"></div>
        <div class="field"><label for="try-title">Title</label><input id="try-title" name="title" type="text" maxlength="300" value="<?= $view->e($trial['title']['text'] ?? '') ?>"></div>
        <div class="field"><label for="try-description">Description</label><textarea id="try-description" name="description" rows="3" maxlength="1000"><?= $view->e($trial['description']['text'] ?? '') ?></textarea></div>
        <button class="button button-secondary">Preview</button>
    </form>
    <?php if ($trial !== null): ?>
    <?= $serp($trial['url'], $trial['title']['text'] !== '' ? $trial['title'] : null, $trial['description']['text'] !== '' ? $trial['description'] : null) ?>
    <p class="small">Title: <?= $view->e($measure($trial['title'])) ?><?= $trial['title']['fits'] ? ' · fits' : ' · cut' ?> · Description: <?= $view->e($measure($trial['description'])) ?><?= $trial['description']['fits'] ? ' · fits' : ' · cut' ?></p>
    <?php endif; ?>
</section>

<section class="card" aria-labelledby="snippet-pages-heading">
    <div class="card-header"><h2 id="snippet-pages-heading">Your pages</h2><a class="button button-ghost button-small" href="<?= $view->e($base . '/snippets' . ($cutOnly ? '' : '?cut=1')) ?>"><?= $cutOnly ? 'Show all pages' : 'Only pages Google cuts' ?></a></div>
    <?php if (!$audited): ?>
    <p>No completed site audit yet. <a href="<?= $view->e($base) ?>">Run an audit</a> to measure your pages.</p>
    <?php else: ?>
    <p class="muted"><?= $view->e(number_format($cut)) ?> of <?= $view->e(number_format($total)) ?> indexable pages have a title or description Google cuts. Widest titles first<?= count($rows) >= SeoControlCenter\Modules\SiteAudit\SnippetPreviewService::PAGES ? '; the first ' . SeoControlCenter\Modules\SiteAudit\SnippetPreviewService::PAGES . ' are shown' : '' ?>.</p>
    <?php foreach ($rows as $row): ?>
    <div class="snippet-row">
        <?= $serp($row['url'], $row['title'], $row['description']) ?>
        <p class="small muted">Title <?= $view->e($measure($row['title'])) ?><?= $row['title'] !== null && !$row['title']['fits'] ? ' · <strong>cut</strong>' : '' ?> · Description <?= $view->e($measure($row['description'])) ?><?= $row['description'] !== null && !$row['description']['fits'] ? ' · <strong>cut</strong>' : '' ?> · <a href="<?= $view->e($base . '/snippets?' . http_build_query(['try' => '1', 'url' => $row['url'], 'title' => $row['title']['text'] ?? '', 'description' => $row['description']['text'] ?? ''])) ?>">Edit in preview</a></p>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</section>
</div>
