<?php
/**
 * A chapter of the packaged user guide, linked from quests.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $title
 * @var string $html escaped by GuideCatalogue (trusted HTML)
 * @var array<string, string> $chapters
 * @var string $current
 */
$view->extend('layouts/app', ['title' => $title, 'crumbs' => [['/quests', 'Quest Log'], [null, $title]]]);
?>
<div class="onboarding-page quest-guide">
    <header class="page-header"><div><p class="portfolio-eyebrow">User guide</p><h1><?= $view->e($title) ?></h1></div><a class="button button-ghost" href="/quests">Back to the Quest Log</a></header>
    <article class="card guide-text"><?= $html ?></article>
    <nav class="card" aria-label="Guide chapters"><h2>All chapters</h2><ul class="plain-list"><?php foreach ($chapters as $id => $name): ?><li><a href="/quests/guide/<?= $view->e($id) ?>"<?= $id === $current ? ' aria-current="page"' : '' ?>><?= $view->e($name) ?></a></li><?php endforeach; ?></ul></nav>
</div>
