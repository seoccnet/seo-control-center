<?php

declare(strict_types=1);

$view->extend('layouts/app', ['title' => 'Core Web Vitals']);
?>
<div class="page-header"><h1>Core Web Vitals</h1></div>
<p><a class="button button-ghost button-small" href="/integrations">All integrations</a></p>
<?= $view->partial('partials/integration-guide', ['guide' => 'crux', 'open' => false]) ?>
<section class="card"><h2>Chrome UX Report</h2>
    <p>Google's free CrUX API reports mobile real-user measurements over a 28-day collection window. Low-traffic URLs often have no data. Enable the Chrome UX Report API in Google Cloud and use an API key restricted to that API. To run free speed tests (Improve → Speed tests) with the same key, also enable the PageSpeed Insights API and allow it for the key.</p>
    <p><?= $configured ? 'A key is saved. Request a page check to verify access.' : 'No key saved.' ?></p>
    <form class="form" method="post" action="/integrations/web-vitals">
        <?= $view->csrfField() ?>
        <label for="crux-key">API key (blank removes the saved key)</label>
        <input id="crux-key" type="password" name="api_key" autocomplete="new-password" maxlength="256">
        <button class="button" type="submit">Save settings</button>
    </form>
</section>
