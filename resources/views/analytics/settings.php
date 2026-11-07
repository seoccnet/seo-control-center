<?php

declare(strict_types=1);

$view->extend('layouts/app', ['title' => 'Google Analytics 4']);
?>
<div class="page-header"><h1>Google Analytics 4</h1></div>
<p><a class="button button-ghost button-small" href="/integrations">All integrations</a></p>
<?= $view->partial('partials/integration-guide', ['guide' => 'ga4', 'open' => false]) ?>
<section class="card"><h2>Read-only service account</h2>
    <p>Enable the Google Analytics Data API in Google Cloud. Create a service account and grant its email Viewer access to your GA4 properties. Save its JSON credential here, then link each project to a numeric property ID under Analytics. Credentials are encrypted and never shown again.</p>
    <p><?= $configured ? 'A credential is saved. Import a linked property to verify access.' : 'No credential saved.' ?></p>
    <form class="form" method="post" action="/integrations/ga4"><?= $view->csrfField() ?>
        <label for="ga4-credentials">Service-account JSON (blank disconnects; imported data is kept)</label>
        <textarea id="ga4-credentials" name="credentials" rows="4" maxlength="16384" autocomplete="off" spellcheck="false"></textarea>
        <button type="submit" class="button">Save credentials</button>
    </form>
</section>
