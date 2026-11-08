<?php
/** @var SeoControlCenter\Core\View\Template $view
 * @var string $content
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $view->e($title ?? 'Install') ?> · SEO Control Center</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=installer-20261004">
    <link rel="stylesheet" href="/assets/css/installer.css?v=20261009">
    <script src="/assets/js/installer.js?v=20261009" defer></script>
</head>
<body>
    <a class="skip-link" href="#installation">Skip to installation</a>
    <div class="installer-shell">
        <header class="installer-header"><div class="installer-brand"><?= $view->partial('partials/logo-mark') ?><span>SEO Control Center<small>Installation assistant</small></span></div><a href="https://github.com/seoccnet/seo-control-center/blob/main/docs/guide/02-installation.md" target="_blank" rel="noopener noreferrer">Installation guide <span aria-hidden="true">↗</span></a></header>
        <main id="installation" tabindex="-1"><?= $content ?></main>
        <footer class="installer-footer">Self-hosted on your server · Your data stays in your installation.</footer>
    </div>
</body>
</html>
