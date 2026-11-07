<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Core\Branding\Branding $branding
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var int $maxLogoKb
 */
$view->extend('layouts/app', ['title' => 'Branding']);
$value = static fn(string $field, ?string $saved): string => $old[$field] ?? ($saved ?? '');
?>
<div class="page-header">
    <h1>Branding</h1>
</div>

<section class="card">
    <h2>White label</h2>
    <p class="muted">Show this installation under your own name, logo and colour: in the app, on the sign-in page, on report pages and in report e-mails. Empty fields keep the defaults. The sender name of e-mails is set under <a href="/system/email">System → E-mail</a>.</p>
    <form method="post" action="/system/branding" class="form" novalidate>
        <?= $view->csrfField() ?>
        <div class="field<?= isset($errors['product_name']) ? ' has-error' : '' ?>">
            <label for="product_name">Product name</label>
            <input id="product_name" name="product_name" type="text" maxlength="60" placeholder="SEO Control Center" value="<?= $view->e($value('product_name', $branding->productName)) ?>"<?= $view->invalid($errors, 'product_name', 'product_name') ?>>
            <?= $view->fieldError($errors, 'product_name', 'product_name') ?>
        </div>
        <div class="field<?= isset($errors['accent_color']) ? ' has-error' : '' ?>">
            <label for="accent_color">Accent colour</label>
            <input id="accent_color" name="accent_color" type="text" maxlength="7" placeholder="#2f5fd0" spellcheck="false" value="<?= $view->e($value('accent_color', $branding->accentColor)) ?>"<?= $view->invalid($errors, 'accent_color', 'accent_color') ?>>
            <?= $view->fieldError($errors, 'accent_color', 'accent_color') ?>
            <p class="small muted">A hex colour, for example #0f7b6c. It is used for buttons, links and highlights in light and dark mode, and in report e-mails.</p>
        </div>
        <div class="field<?= isset($errors['report_footer']) ? ' has-error' : '' ?>">
            <label for="report_footer">Report footer</label>
            <textarea id="report_footer" name="report_footer" rows="3" maxlength="300" placeholder="Prepared by Your Agency · hello@your-agency.example"<?= $view->invalid($errors, 'report_footer', 'report_footer') ?>><?= $view->e($value('report_footer', $branding->reportFooter)) ?></textarea>
            <?= $view->fieldError($errors, 'report_footer', 'report_footer') ?>
            <p class="small muted">Replaces "Sent by SEO Control Center" at the end of report pages and e-mails.</p>
        </div>
        <button type="submit" class="button">Save branding</button>
    </form>
</section>

<section class="card">
    <h2>Logo</h2>
    <?php if ($branding->hasLogo()): ?>
        <p><img class="brand-logo brand-logo-preview" src="<?= $view->e($branding->logoUrl()) ?>" alt="Current logo"></p>
        <form method="post" action="/system/branding/logo/remove" class="inline-form">
            <?= $view->csrfField() ?>
            <button type="submit" class="button button-ghost button-small">Remove logo</button>
        </form>
    <?php else: ?>
        <p class="muted">The default logo is shown.</p>
    <?php endif; ?>
    <?php if (isset($errors['logo'])): ?><p class="notice notice-error" role="alert"><?= $view->e($errors['logo']) ?></p><?php endif; ?>
    <form method="post" action="/system/branding/logo" enctype="multipart/form-data" class="form">
        <?= $view->csrfField() ?>
        <div class="field">
            <label for="logo"><?= $branding->hasLogo() ? 'Replace logo' : 'Upload a logo' ?></label>
            <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" required>
            <p class="small muted">PNG, JPEG or WebP, up to <?= $view->e($maxLogoKb) ?> KB. Shown 30 pixels high next to the product name, so a square or wide logo on a transparent background works best. Report e-mails show the name only, because e-mails do not load images.</p>
        </div>
        <button type="submit" class="button">Upload</button>
    </form>
</section>
