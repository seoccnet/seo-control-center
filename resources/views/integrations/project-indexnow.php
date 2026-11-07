<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Modules\IndexNow\IndexNowSettings|null $settings
 * @var list<array{source: string, url_count: int, status_code: ?int, result: string, created_at: string}> $submissions
 * @var list<string> $hosts
 * @var bool $canManage
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$view->extend('layouts/app', ['title' => 'IndexNow · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], ['/projects/' . $project->publicId . '/edit', 'Settings'], [null, 'IndexNow']]]);
$base = '/projects/' . $project->publicId . '/indexnow';
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'settings']) ?>

<?= $view->partial('partials/integration-guide', ['guide' => 'indexnow', 'open' => false]) ?>

<?php if ($canManage): ?>
<section class="card" id="wordpress-companion" aria-labelledby="wordpress-companion-heading">
    <h2 id="wordpress-companion-heading">SEOcc · WordPress companion</h2>
    <p>SEOcc is an optional WordPress plugin that helps search engines discover your content changes sooner through IndexNow.</p>
    <ul><li>Serves this project’s public IndexNow key file on your website.</li><li>Notifies IndexNow when public posts, pages and public custom post types are published or updated, including old URLs after permalink changes or content removal.</li><li>Runs notifications in WordPress background tasks, retries temporary failures and shows the latest result in WordPress.</li></ul>
    <p><a class="button button-ghost button-small" href="<?= $view->e($base) ?>/wordpress-plugin">Download SEOcc WordPress plugin</a></p>
    <h3>Install and connect</h3>
    <ol><li>If not already set up, generate this project’s key using <strong>Set up IndexNow</strong> below.</li><li>In WordPress, open <strong>Plugins → Add New → Upload Plugin</strong>, upload the ZIP and activate <strong>SEOcc</strong>.</li><li>Open <strong>Settings → SEOcc</strong>, paste this project’s IndexNow key and save.</li><li>Open the plugin’s key-file link, then use <strong>Check key file</strong> in SEOcc below.</li><li>Enable notifications in WordPress when you are ready. Publish or update a public post and check the plugin’s latest result.</li></ol>
    <p class="muted small">Requires WordPress 6.5+, PHP 8.1+, a public HTTPS home address and working WordPress routing/cron. In a multisite network, each site has its own SEOcc settings; paste the key of the SEOcc project for that site’s domain. For a subdirectory home address such as https://example.com/blog/, the plugin serves the key file under that path and notifies only URLs under it; enter that path (for example <code>/blog/</code>) as the <strong>Site path</strong> when you choose <strong>Check key file</strong> below.</p>
    <p class="muted small">Notifications are opt-in. Draft/private/password-protected content is excluded, and activation does not submit existing content. Only changed URLs, the website host and public key are sent to IndexNow; no page content or SEOcc credentials. IndexNow does not include Google, and acceptance does not guarantee indexing or rankings.</p>
    <p class="muted small">Plugin results appear in WordPress, separately from SEOcc’s sitemap submissions. Avoid a second IndexNow plugin; enabling both plugin notifications and SEOcc sitemap submissions can duplicate notifications. This explanation and download stay here after you hide the Overview notice.</p>
</section>
<?php endif; ?>

<section class="card">
    <h2>IndexNow</h2>
    <p class="muted">IndexNow tells Bing and the other participating search engines (Yandex, Seznam, Naver and more) right away when a page is new or has changed, instead of waiting for the next crawl. Bing's results also feed Yahoo, DuckDuckGo and the web search of ChatGPT and Copilot. Google does not take part.</p>

    <?php if ($settings === null): ?>
        <?php if ($canManage): ?>
            <form method="post" action="<?= $view->e($base) ?>/setup">
                <?= $view->csrfField() ?>
                <button type="submit" class="button">Set up IndexNow</button>
            </form>
        <?php else: ?>
            <p class="muted">Not set up. A workspace owner or admin can set it up.</p>
        <?php endif; ?>
    <?php else: ?>
        <h3>1. Key file</h3>
        <?php if ($settings->isVerified()): ?>
            <p><span class="badge badge-ok">Verified</span> <code><?= $view->e($settings->keyUrl((string) $settings->host)) ?></code> <span class="muted small">checked <?= $view->e($settings->verifiedAt) ?> UTC</span></p>
            <p class="muted small">Leave the file on your site: the engines look it up whenever URLs are submitted.<?php if ($settings->keyPath !== '/'): ?> Because the key is in <code><?= $view->e($settings->keyPath) ?></code>, only addresses under that path are submitted.<?php endif; ?></p>
        <?php else: ?>
            <p>Put a file named <code><?= $view->e($settings->fileName()) ?></code> containing only the key into the root folder of your website, so it opens at <code>https://<?= $view->e($hosts[0] ?? $project->primaryDomain) ?>/<?= $view->e($settings->fileName()) ?></code>. With cPanel: File Manager → <code>public_html</code> → Upload. If your site’s public address is in a subdirectory, such as <code>https://<?= $view->e($hosts[0] ?? $project->primaryDomain) ?>/blog/</code> (for example with the SEOcc WordPress plugin), enter that path below; the key then covers only addresses under it.</p>
        <?php endif; ?>
        <p class="small">Key: <code class="token-value"><?= $view->e($settings->key) ?></code></p>
        <?php if (isset($errors['key'])): ?><p class="notice notice-error" role="alert"><?= $view->e($errors['key']) ?></p><?php endif; ?>
        <?php if ($canManage): ?>
            <div class="actions">
                <a class="button button-ghost button-small" href="<?= $view->e($base) ?>/key-file"><?= $view->icon('download') ?>Download key file</a>
                <form method="post" action="<?= $view->e($base) ?>/verify" class="inline-form">
                    <?= $view->csrfField() ?>
                    <label for="key_path">Site path <span class="muted small">(optional)</span></label>
                    <input id="key_path" name="key_path" type="text" maxlength="200" placeholder="/" value="<?= $view->e($old['key_path'] ?? ($settings->keyPath !== '/' ? $settings->keyPath : '')) ?>"<?= $view->invalid($errors, 'key_path', 'key_path') ?>><?= $view->fieldError($errors, 'key_path', 'key_path') ?>
                    <button type="submit" class="button button-small"><?= $settings->isVerified() ? 'Check again' : 'Check key file' ?></button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($settings !== null && $settings->isVerified()): ?>
    <section class="card">
        <h2>2. Automatic from your sitemap</h2>
        <p class="muted">Every 6 hours the sitemap is read. The first check only records the URLs it lists; after that, new URLs and URLs with a changed <code>lastmod</code> are submitted automatically.</p>
        <?php if ($settings->sitemapCheckedAt !== null): ?>
            <p class="muted small">Last check <?= $view->e($settings->sitemapCheckedAt) ?> UTC<?= $settings->lastError === null ? ' · OK' : '' ?></p>
        <?php endif; ?>
        <?php if ($settings->lastError !== null): ?><p class="notice notice-error"><?= $view->e($settings->lastError) ?></p><?php endif; ?>
        <?php if ($canManage): ?>
            <form method="post" action="<?= $view->e($base) ?>/sitemap" class="form" novalidate>
                <?= $view->csrfField() ?>
                <div class="field<?= isset($errors['sitemap_url']) ? ' has-error' : '' ?>">
                    <label for="sitemap_url">Sitemap address</label>
                    <input id="sitemap_url" name="sitemap_url" type="url" maxlength="2048" placeholder="https://<?= $view->e($settings->host) ?>/sitemap.xml" value="<?= $view->e($old['sitemap_url'] ?? ($settings->sitemapUrl ?? '')) ?>"<?= $view->invalid($errors, 'sitemap_url', 'sitemap_url') ?>>
                    <?= $view->fieldError($errors, 'sitemap_url', 'sitemap_url') ?>
                    <p class="small muted">A sitemap index works too (up to 50 sitemaps). Enter the final address: redirects are not followed.</p>
                </div>
                <label><input type="checkbox" name="auto_submit" value="1"<?= $settings->autoSubmit ? ' checked' : '' ?>> Submit new and changed URLs automatically</label>
                <button type="submit" class="button">Save</button>
            </form>
        <?php else: ?>
            <p><?= $settings->autoSubmit ? 'Automatic submissions are on for ' . $view->e($settings->sitemapUrl) . '.' : 'Automatic submissions are off.' ?></p>
        <?php endif; ?>
    </section>

    <?php if ($canManage): ?>
        <section class="card">
            <h2>3. Submit URLs now</h2>
            <form method="post" action="<?= $view->e($base) ?>/submit" class="form form-wide" novalidate>
                <?= $view->csrfField() ?>
                <div class="field<?= isset($errors['urls']) ? ' has-error' : '' ?>">
                    <label for="urls">URLs on https://<?= $view->e($settings->host . $settings->keyPath) ?>, one per line (up to 1,000)</label>
                    <textarea id="urls" name="urls" rows="6" spellcheck="false"<?= $view->invalid($errors, 'urls', 'urls') ?>><?= $view->e($old['urls'] ?? '') ?></textarea>
                    <?= $view->fieldError($errors, 'urls', 'urls') ?>
                </div>
                <button type="submit" class="button">Submit to IndexNow</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Recent submissions</h2>
        <?php if ($submissions === []): ?>
            <p class="muted">Nothing submitted yet.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table table-dense">
                    <thead><tr><th scope="col">Time (UTC)</th><th scope="col">Source</th><th scope="col" class="num">URLs</th><th scope="col">Result</th></tr></thead>
                    <tbody>
                        <?php foreach ($submissions as $row): ?>
                            <tr>
                                <td><?= $view->e($row['created_at']) ?></td>
                                <td><?= $row['source'] === 'sitemap' ? 'Sitemap' : 'By hand' ?></td>
                                <td class="num"><?= $view->e(number_format($row['url_count'])) ?></td>
                                <td><?php if ($row['status_code'] === 200 || $row['status_code'] === 202): ?><span class="badge badge-ok"><?= $view->e($row['status_code']) ?></span><?php elseif ($row['status_code'] !== null): ?><span class="badge badge-error"><?= $view->e($row['status_code']) ?></span><?php endif; ?> <?= $view->e($row['result']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
