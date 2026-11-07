<?php
/** Settings wayfinding uses the same permission-filtered links as the sidebar. */
$system = str_starts_with($path, '/system/');
$section = $system ? 'System' : 'Workspace';
$links = $groups[$section] ?? [];
?>
<?php if ($links !== []): ?>
<section class="settings-context" aria-label="<?= $view->e($section) ?> settings">
    <div><p class="portfolio-eyebrow"><?= $view->e($section) ?></p><p class="settings-context-note"><?= $system ? 'Manage this installation, its services and maintenance.' : 'Manage connections, access and workspace activity.' ?></p></div>
    <nav class="settings-tabs" aria-label="<?= $view->e($section) ?> settings pages">
        <?php foreach ($links as [$href, $label, $icon]): ?>
        <a href="<?= $view->e($href) ?>"<?= $isActive($href) ? ' aria-current="page"' : '' ?>><?= $view->icon($icon) ?><span><?= $view->e($label) ?></span></a>
        <?php endforeach; ?>
    </nav>
</section>
<?php endif; ?>
