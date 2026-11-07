<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $clientId
 * @var bool $hasSecret
 * @var string $redirectUri
 * @var array<string, string> $errors
 */
$view->extend('layouts/app', ['title' => 'Google integration settings']);
?>
<div class="page-header">
    <h1>Google OAuth client</h1>
</div>
<p><a class="button button-ghost button-small" href="/integrations">All integrations</a></p>
<?= $view->partial('partials/integration-guide', ['guide' => 'google-app', 'open' => false]) ?>

<section class="card">
    <p>Each installation uses its own Google Cloud OAuth client, so your data never passes through the vendor.</p>
    <ol class="steps">
        <li>In <strong>Google Cloud Console</strong> create a project and enable the <strong>Google Search Console API</strong>.</li>
        <li>Configure the <strong>OAuth consent screen</strong> (app name, support e-mail) and add the scope <code>…/auth/webmasters.readonly</code>.</li>
        <li>Create an <strong>OAuth client ID</strong> of type <em>Web application</em> with this <strong>authorized redirect URI</strong>:
            <p><code><?= $view->e($redirectUri) ?></code></p>
            <p class="muted small">Google only accepts HTTPS addresses on a public domain, or <code>http://localhost</code>. While the consent screen is in <em>Testing</em> mode, Google expires refresh tokens after 7 days; publish the app for permanent connections.</p>
        </li>
        <li>Paste the client ID and secret below.</li>
    </ol>

    <form method="post" action="/system/integrations/google" class="form" novalidate>
        <?= $view->csrfField() ?>
        <div class="field<?= isset($errors['client_id']) ? ' has-error' : '' ?>">
            <label for="client_id">Client ID</label>
            <input id="client_id" name="client_id" type="text" required maxlength="255" value="<?= $view->e($clientId) ?>" autocomplete="off"<?= $view->invalid($errors, 'client_id', 'client_id') ?>>
            <?= $view->fieldError($errors, 'client_id', 'client_id') ?>
        </div>
        <div class="field<?= isset($errors['client_secret']) ? ' has-error' : '' ?>">
            <label for="client_secret">Client secret</label>
            <input id="client_secret" name="client_secret" type="password" maxlength="255" autocomplete="new-password" placeholder="<?= $hasSecret ? 'Stored — leave empty to keep' : '' ?>"<?= $view->invalid($errors, 'client_secret', 'client_secret') ?>>
            <?= $view->fieldError($errors, 'client_secret', 'client_secret') ?>
            <p class="muted small">Stored encrypted. It is never shown again.</p>
        </div>
        <button type="submit" class="button">Save</button>
    </form>
</section>
