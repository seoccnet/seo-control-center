<?php
/**
 * Application shell. Shows navigation when a user is signed in.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $content  rendered page (trusted HTML)
 * @var string $appName
 * @var string|null $assetVersion
 * @var string $sourceUrl
 * @var (Closure(): SeoControlCenter\Core\Branding\Branding)|null $brand  white label (System → Branding)
 * @var list<array{0: string|null, 1: string}>|null $crumbs  breadcrumb trail: [href or null, label]
 * @var (Closure(string, SeoControlCenter\Core\View\Template): string)|null $widgets  module widgets per slot (Core\View\LayoutWidgets)
 * @var array{email: string, community: string}|null $support  help links in the sidebar
 */
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Workspaces\CurrentWorkspace;

$branding = isset($brand) ? $brand() : SeoControlCenter\Core\Branding\Branding::none();
$appName = $branding->name($appName);
$user = $view->attribute(RequestAttribute::USER);
$context = $view->attribute(RequestAttribute::WORKSPACE);
$path = $view->path();
$isAdmin = $user instanceof User && $user->systemRole === SystemRole::Admin;
$groups = [
    '' => [['/', 'Dashboard', 'dashboard'], ['/quests', 'Quest Log', 'list'], ['/projects', 'Projects', 'folder']],
    'Workspace' => [['/integrations', 'Integrations', 'plug'], ['/workspace/members', 'Members', 'users'], ['/audit', 'Audit log', 'list'], ['/workspace/settings', 'Settings', 'settings']],
];
if ($isAdmin) {
    $groups['System'] = [
        ['/system/health', 'Health', 'pulse'],
        ['/system/jobs', 'Jobs', 'layers'],
        ['/system/backups', 'Backups', 'database'],
        ['/system/users', 'Users', 'users'],
        ['/system/email', 'E-mail', 'mail'],
        ['/system/branding', 'Branding', 'settings'],
        ['/system/updates', 'Updates', 'download'],
        ['/system/integrations/google', 'Google app', 'google'],
    ];
}
$settingsWorkspace = preg_match('#^/(system|integrations|audit)(/|$)|^/workspace/(members|settings)(/|$)#', $path) === 1;
// Report a bug / community: SEO Control Center's own channels, so not on white-label installations.
$help = [];
if (isset($support) && $branding->productName === null) {
    if ($support['email'] !== '') {
        $body = "What happened:\n\n\nSteps to reproduce:\n1. \n2. \n\nWhat you expected instead:\n\n\n---\nPage: " . $path . "\nVersion: " . (string) ($appVersion ?? 'unknown') . "\nIf you can, attach the support bundle from System health (it contains no passwords, keys or SEO data).";
        $help[] = ['mailto:' . $support['email'] . '?subject=' . rawurlencode('Bug report: ' . $appName . ' ' . (string) ($appVersion ?? '')) . '&body=' . rawurlencode($body), 'Report a bug', 'bug', false];
    }
    if ($support['email'] === '' && isset($sourceUrl) && str_starts_with($sourceUrl, 'http')) {
        $help[] = [$sourceUrl . '/issues/new/choose', 'Report a bug', 'bug', true];
    }
    if ($support['community'] !== '' && str_starts_with($support['community'], 'https://')) {
        $help[] = [$support['community'], 'Discord community', 'discord', true];
    }
}
// AGPL-3.0 section 13: always offered, also on white-label installations.
if (isset($sourceUrl) && str_starts_with($sourceUrl, 'http')) {
    $help[] = [$sourceUrl, 'Source code', 'layers', true];
}
$widget = static fn(string $slot): string => isset($widgets) && $widgets instanceof Closure ? $widgets($slot, $view) : '';
$isActive = static fn(string $href): bool => $href === '/' ? $path === '/' : ($path === $href || str_starts_with($path, $href . '/'));
$crumbs ??= [[null, $title ?? $appName]];
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $letters = array_map(static fn(string $p): string => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

    return implode('', $letters) ?: '?';
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">
    <title><?= $view->e($title ?? $appName) ?> · <?= $view->e($appName) ?></title>
    <script nonce="<?= $view->nonce() ?>">try { var t = localStorage.getItem('scc-theme'); if (t === 'light' || t === 'dark') { document.documentElement.dataset.theme = t; } } catch (e) {}</script>
    <?php if ($branding->hasLogo()): ?><link rel="icon" href="<?= $view->e($branding->logoUrl()) ?>"><?php else: ?><link rel="icon" href="/assets/img/logo.svg" type="image/svg+xml"><?php endif; ?>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $view->e($assetVersion ?? '0') ?>">
    <link rel="stylesheet" href="/assets/css/workspace-ui.css?v=<?= $view->e($assetVersion ?? '0') ?>">
    <link rel="stylesheet" href="/assets/css/github-ui.css?v=<?= $view->e($assetVersion ?? '0') ?>">
    <?php if ($branding->stylesheetUrl() !== null): ?><link rel="stylesheet" href="<?= $view->e($branding->stylesheetUrl()) ?>"><?php endif; ?>
</head>
<body>
<?php if ($user instanceof User): ?>
    <a class="skip-link" href="#content">Skip to content</a>
    <div class="shell">
        <aside class="sidebar" id="app-navigation">
            <a class="brand" href="/"><?= $view->partial('partials/logo-mark') ?><span><?= $view->e($appName) ?></span></a>
            <?php if ($context instanceof CurrentWorkspace && count($context->all) > 1): ?>
                <form method="post" action="/workspaces/switch" class="workspace-switch">
                    <?= $view->csrfField() ?>
                    <label for="workspace-switch" class="visually-hidden">Workspace</label>
                    <select id="workspace-switch" name="workspace" data-autosubmit>
                        <?php foreach ($context->all as $membership): ?>
                            <option value="<?= $view->e($membership->workspace->publicId) ?>"<?= $membership->workspace->id === $context->id() ? ' selected' : '' ?>><?= $view->e($membership->workspace->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="button button-small">Switch</button></noscript>
                </form>
            <?php elseif ($context instanceof CurrentWorkspace): ?>
                <div class="workspace-name"><span class="avatar" aria-hidden="true"><?= $view->e($initials($context->workspace()->name)) ?></span><?= $view->e($context->workspace()->name) ?></div>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <a class="workspace-new" href="/workspaces/new"><?= $view->icon('plus') ?><span>New workspace</span></a>
            <?php endif; ?>
            <nav class="nav" aria-label="Main">
                <?php foreach ($groups as $label => $items): ?>
                    <?php if ($label === 'System'): ?><details class="sidebar-section"<?= str_starts_with($path, '/system') ? ' open' : '' ?>><summary><?= $view->icon('settings') ?><span>Administration</span><span class="sidebar-section-chevron" aria-hidden="true">⌄</span></summary><?php elseif ($label !== ''): ?><div class="nav-label" id="nav-<?= $view->e(strtolower((string) $label)) ?>"><?= $view->e($label) ?></div><?php endif; ?>
                    <ul<?= $label !== '' && $label !== 'System' ? ' aria-labelledby="nav-' . $view->e(strtolower((string) $label)) . '"' : ($label === 'System' ? ' aria-label="Administration"' : '') ?>>
                        <?php foreach ($items as [$href, $text, $icon]): ?>
                            <li><a href="<?= $view->e($href) ?>"<?= $isActive($href) ? ' aria-current="page"' : '' ?>><?= $view->icon($icon) ?><span><?= $view->e($text) ?></span></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($label === 'System'): ?></details><?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <?php if ($help !== []): ?>
                <ul class="sidebar-help" aria-label="Help">
                    <?php foreach ($help as [$href, $text, $icon, $external]): ?>
                        <li><a href="<?= $view->e($href) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= $view->icon($icon) ?><span><?= $view->e($text) ?></span></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="sidebar-user">
                <span class="avatar" aria-hidden="true"><?= $view->e($initials($user->name)) ?></span>
                <span class="sidebar-user-text">
                    <a class="sidebar-user-name" href="/account"><?= $view->e($user->name) ?></a>
                    <span class="sidebar-user-role"><?= $isAdmin ? 'Installation admin' : 'Your account' ?></span>
                </span>
                <form method="post" action="/logout" class="inline-form">
                    <?= $view->csrfField() ?>
                    <button type="submit" class="icon-button" aria-label="Sign out" title="Sign out"><?= $view->icon('logout') ?></button>
                </form>
            </div>
        </aside>
        <button type="button" class="navigation-scrim" data-navigation-dismiss aria-label="Close navigation" hidden></button>
        <div class="main">
            <header class="topbar">
                <button type="button" class="icon-button navigation-toggle" data-navigation-toggle aria-label="Open navigation" aria-controls="app-navigation" aria-expanded="false" hidden><?= $view->icon('list') ?></button>
                <nav class="crumbs" aria-label="Breadcrumb">
                    <?php foreach ($crumbs as $i => [$href, $label]): ?>
                        <?php if ($i > 0): ?><span aria-hidden="true">/</span><?php endif; ?>
                        <?php if ($href !== null && $i < count($crumbs) - 1): ?>
                            <a href="<?= $view->e($href) ?>"><?= $view->e($label) ?></a>
                        <?php else: ?>
                            <span aria-current="page"><?= $view->e($label) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
                <div class="topbar-user">
                    <?= $widget(SeoControlCenter\Core\View\LayoutWidgets::TOPBAR) ?>
                    <button type="button" class="icon-button" data-theme-toggle aria-label="Switch between dark and light mode" title="Dark / light mode"><?= $view->icon('sun') ?></button>
                </div>
            </header>
            <main class="content<?= preg_match('#^/projects/[^/]+/(pages|keywords|rankings|competitors|content-briefs|site-audit|page-monitoring|backlinks|web-vitals|indexing|ai-visibility|local)(/|$)#', $path) === 1 ? ' module-workspace' : '' ?><?= $settingsWorkspace ? ' settings-workspace' : '' ?><?= $settingsWorkspace || preg_match('#^/account(/|$)|^/(projects|workspaces)/new$|^/projects/[^/]+/(edit|reports|annotations|search-console|indexnow|bing)$#', $path) === 1 ? ' page-columns' : '' ?>" id="content" tabindex="-1">
                <?php if ($settingsWorkspace): ?><?= $view->partial('partials/settings-navigation', compact('path', 'groups', 'isActive')) ?><?php endif; ?>
                <?php if (($status = $view->flash('status')) !== null): ?>
                    <p class="notice notice-success" role="status"><?= $view->e($status) ?></p>
                <?php endif; ?>
                <?php if (($error = $view->flash('error')) !== null): ?>
                    <p class="notice notice-error" role="alert"><?= $view->e($error) ?></p>
                <?php endif; ?>
                <?php if (($repeat = SeoControlCenter\Modules\Shared\Http\RepeatConfirmation::read($view->flash(SeoControlCenter\Modules\Shared\Http\RepeatConfirmation::FLASH))) !== null): ?>
                    <form method="post" action="<?= $view->e($repeat['action']) ?>" class="notice notice-warning repeat-confirmation" role="alert">
                        <?= $view->csrfField() ?>
                        <?php foreach ($repeat['fields'] as $name => $value): ?><input type="hidden" name="<?= $view->e($name) ?>" value="<?= $view->e($value) ?>"><?php endforeach; ?>
                        <input type="hidden" name="<?= $view->e(SeoControlCenter\Modules\Shared\Http\RepeatConfirmation::FIELD) ?>" value="1">
                        <span><?= $view->e($repeat['message']) ?></span>
                        <button type="submit" class="button button-small">Run again and pay</button>
                    </form>
                <?php endif; ?>
                <?= $content ?>
            </main>
        </div>
    </div>
    <?= $widget(SeoControlCenter\Core\View\LayoutWidgets::FLOATING) ?>
    <script type="module" src="/assets/js/app.js?v=<?= $view->e($assetVersion ?? '0') ?>"></script>
<?php else: ?>
    <main class="page">
        <div class="auth-brand"><?= $view->partial('partials/logo-mark') ?><span><?= $view->e($appName) ?></span></div>
        <?php if (($status = $view->flash('status')) !== null): ?>
            <p class="notice notice-success auth-notice" role="status"><?= $view->e($status) ?></p>
        <?php endif; ?>
        <?= $content ?>
    </main>
<?php endif; ?>
</body>
</html>
