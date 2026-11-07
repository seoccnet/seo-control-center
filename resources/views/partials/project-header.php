<?php
/**
 * Header of every project page: name, domain, status line, actions and the
 * project tabs. The page's other headings start at h2.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var string $active  overview|performance|pages|keywords|rankings|competitors|article-ideas|topic-plans|backlinks|ai-visibility|local|opportunities|site-audit|reports|settings
 * @var string|null $meta  status line after the domain
 * @var string|null $metaStatus  ok|warn|error: colour of the status dot
 * @var string|null $actions  trusted HTML (buttons, forms)
 * @var string|null $notice  trusted HTML: a compact notice beside the title
 */
$base = '/projects/' . $project->publicId;
$tabs = [
    'overview' => ['Overview', $base],
    'performance' => ['Performance', $base . '/performance'],
    'analytics' => ['Analytics', $base . '/analytics'],
    'pages' => ['Pages', $base . '/pages'],
    'inventory' => ['Content inventory', $base . '/inventory'],
    'indexing' => ['Google indexing', $base . '/indexing'],
    'page-monitoring' => ['Page monitoring', $base . '/page-monitoring'],
    'keywords' => ['Keywords', $base . '/keywords'],
    'rankings' => ['Rankings', $base . '/rankings'],
    'competitors' => ['Competitors', $base . '/competitors'],
    'article-ideas' => ['Article ideas', $base . '/article-ideas'],
    'topic-plans' => ['Topic plans', $base . '/topic-plans'],
    'backlinks' => ['Backlinks', $base . '/backlinks'],
    'ai-visibility' => ['AI visibility', $base . '/ai-visibility'],
    'local' => ['Local', $base . '/local'],
    'opportunities' => ['Opportunities', $base . '/opportunities'],
    'site-audit' => ['Site audit', $base . '/site-audit'],
    'lab-tests' => ['Speed tests', $base . '/lab-tests'],
    'reports' => ['Reports', $base . '/reports'],
    'annotations' => ['Change timeline', $base . '/annotations'],
    'change-bundles' => ['Change bundles', $base . '/change-bundles'],
    'data-quality' => ['Data quality', $base . '/data-quality'],
    'content-briefs' => ['Content briefs', $base . '/content-briefs'],
    'settings' => ['Settings', $base . '/edit'],
];
$initial = mb_strtoupper(mb_substr($project->name, 0, 1)) ?: '?';
$tabGroups = [
    'Research' => ['pages', 'inventory', 'keywords', 'rankings', 'competitors', 'article-ideas', 'topic-plans'],
    'Improve' => ['content-briefs', 'site-audit', 'lab-tests', 'page-monitoring', 'backlinks'],
    'Visibility' => ['indexing', 'ai-visibility', 'local'],
    'Manage' => ['annotations', 'change-bundles', 'data-quality', 'reports', 'settings'],
];
$groupIcons = ['Research' => 'search', 'Improve' => 'pulse', 'Visibility' => 'eye', 'Manage' => 'settings'];
?>
<header class="project-header">
    <div class="project-title-row">
        <span class="project-avatar" aria-hidden="true"><?= $view->e($initial) ?></span>
        <div class="project-title">
            <h1><?= $view->e($project->name) ?></h1>
            <div class="project-meta">
                <span><?= $view->e($project->primaryDomain) ?></span>
                <?php if (($meta ?? null) !== null): ?>
                    <span class="sep" aria-hidden="true"></span>
                    <span class="status-dot<?= isset($metaStatus) ? ' is-' . $view->e($metaStatus) : '' ?>" aria-hidden="true"></span>
                    <span><?= $view->e($meta) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?= $notice ?? '' ?>
        <a class="button button-ghost button-small" href="/quests?chapter=foundations&amp;project=<?= $view->e($project->publicId) ?>">Setup guide</a>
        <?php if (($actions ?? null) !== null): ?>
            <div class="actions"><?= $actions ?></div>
        <?php endif; ?>
    </div>
    <div class="project-nav-row">
    <nav class="project-primary-tabs" aria-label="Primary project sections">
        <?php foreach (['overview', 'performance', 'analytics', 'opportunities'] as $key): [$label, $href] = $tabs[$key]; ?>
            <a href="<?= $view->e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= $view->e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <nav class="project-navigation" aria-label="Project sections">
        <?php foreach ($tabGroups as $group => $keys): ?>
            <details class="project-nav-group" name="project-sections" data-project-nav<?= in_array($active, $keys, true) ? ' data-active-group' : '' ?>>
                <summary>
                    <?= $view->icon($groupIcons[$group]) ?>
                    <span class="project-nav-label"><?= $view->e($group) ?></span>
                    <svg class="project-nav-chevron" viewBox="0 0 16 16" width="16" height="16" fill="none" aria-hidden="true"><path d="m4 6 4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </summary>
                <div class="project-nav-links">
                    <?php foreach ($keys as $key): [$label, $href] = $tabs[$key]; ?>
                        <a href="<?= $view->e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><span><?= $view->e($label) ?></span><?php if ($key === $active): ?><span class="project-nav-check" aria-hidden="true">✓</span><?php endif; ?></a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </nav>
    </div>
</header>

<?= $view->partial('partials/module-navigation', ['project' => $project, 'active' => $active]) ?>
