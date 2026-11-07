<?php
/**
 * Switch between the Google (Search Console) and Bing performance views.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $base project path
 * @var string $current google|bing
 */
$sources = ['google' => ['Google', $base . '/performance'], 'bing' => ['Bing', $base . '/performance/bing']];
?>
<nav class="segmented source-switch" aria-label="Search engine">
    <?php foreach ($sources as $key => [$label, $href]): ?>
        <a href="<?= $view->e($href) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= $view->e($label) ?></a>
    <?php endforeach; ?>
</nav>
