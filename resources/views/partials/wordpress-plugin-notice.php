<?php

declare(strict_types=1);

/** Compact WordPress companion hint for the project header; dismissal is per user and project. */
/** @var SeoControlCenter\Core\View\Template $view */
/** @var SeoControlCenter\Domain\Projects\Project $project */
$noticeUser = $view->attribute(SeoControlCenter\Core\Http\RequestAttribute::USER);
if (!$noticeUser instanceof SeoControlCenter\Domain\Accounts\User) {
    return;
}
$noticeBase = '/projects/' . $project->publicId . '/indexnow';
?>
<aside class="header-notice" aria-labelledby="wordpress-plugin-heading" data-wordpress-notice="<?= $view->e('0.1.0:' . $noticeUser->publicId . ':' . $project->publicId) ?>">
    <span class="header-notice-icon" aria-hidden="true">W</span>
    <span class="header-notice-text"><strong id="wordpress-plugin-heading">Use WordPress? Meet SEOcc</strong><span class="muted" title="SEOcc serves the IndexNow key file and notifies Bing and other participating search engines when public content changes. You choose when to enable notifications.">IndexNow for your site</span></span>
    <a class="header-notice-link" href="<?= $view->e($noticeBase) ?>/wordpress-plugin"><?= $view->icon('download') ?>Plugin</a>
    <a class="header-notice-link" href="<?= $view->e($noticeBase) ?>#wordpress-companion">Setup</a>
    <button class="header-notice-close" type="button" aria-label="Hide the SEOcc notice" data-wordpress-dismiss hidden><?= $view->icon('close') ?></button>
</aside>
