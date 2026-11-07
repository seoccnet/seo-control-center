<?php
/**
 * Date range switcher (7d … 16m) as links with ?range=.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var array<string, int> $ranges  key => days
 * @var string $current
 * @var string $href  page path without query
 */
$short = ['7d' => '7d', '28d' => '28d', '3m' => '3m', '6m' => '6m', '12m' => '12m', '16m' => '16m'];
?>
<nav class="segmented" aria-label="Date range">
    <?php foreach ($ranges as $key => $days): ?>
        <a href="<?= $view->e($href) ?>?range=<?= $view->e($key) ?>"<?= $key === $current ? ' aria-current="true"' : '' ?> aria-label="Last <?= $view->e($days) ?> days"><?= $view->e($short[$key] ?? $key) ?></a>
    <?php endforeach; ?>
</nav>
