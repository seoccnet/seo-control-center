<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Modules\Research\ResearchResult|null $result
 * @var bool $available
 * @var bool $canRun
 */
$row = $result?->items[0] ?? null;
$savedTask = $result?->status === 'failed' && isset($result->params['_batch']['done']['submission']);
?>
<section class="card improve-diagnostics" id="audit-browser-diagnostics"<?= $result?->isQueued() ? ' data-refresh-seconds="15"' : '' ?>>
    <h2>Browser-rendered auditing</h2>
    <p class="muted">Render a selected page or crawl its site through DataForSEO’s mobile browser. Store DOM evidence, provider resource status and waterfall timings. Lighthouse diagnostics and Google real-user Core Web Vitals are separate measurements.</p>
    <?php if (!$available): ?>
        <p class="notice">Connect DataForSEO under Integrations to run rendered checks.</p>
    <?php elseif ($canRun): ?>
        <form method="post" action="/projects/<?= $view->e($project->publicId) ?>/site-audit/render" class="form">
            <?= $view->csrfField() ?>
            <div class="field">
                <label for="render-url">Page URL</label>
                <input id="render-url" name="url" type="url" maxlength="2048" required placeholder="https://<?= $view->e($project->primaryDomain) ?>/" value="<?= $view->e($result?->params['url'] ?? '') ?>">
                <p class="small muted">Start from a known HTTPS page on this project’s current domain. Site crawls discover internal pages up to the chosen limit. No automatic weekly paid rendering.</p>
            </div>
            <label for="render-mode">Diagnostic</label><select id="render-mode" name="mode"><option value="browser" <?= ($result?->params['mode'] ?? 'browser') === 'browser' ? 'selected' : '' ?>>Browser crawl</option><option value="lighthouse" <?= ($result?->params['mode'] ?? '') === 'lighthouse' ? 'selected' : '' ?>>Lighthouse mobile (one page)</option></select>
            <label for="render-limit">Page limit</label><select id="render-limit" name="max_pages"><?php foreach ([1, 10, 50, 100] as $pageLimit): ?><option value="<?= $view->e($pageLimit) ?>" <?= ($result?->params['max_pages'] ?? 1) === $pageLimit ? 'selected' : '' ?>><?= $view->e($pageLimit) ?> · browser estimate $<?= $view->e(number_format(SeoControlCenter\Modules\SiteAudit\RenderedPageProvider::ESTIMATED_COST * $pageLimit, 4)) ?></option><?php endforeach; ?></select>
            <label><input type="checkbox" name="dismiss_cookie_popup" value="1" <?= ($result?->params['dismiss_cookie_popup'] ?? false) ? 'checked' : '' ?>> Ask the browser provider to dismiss cookie popups</label>
            <p class="small">Lighthouse estimate: $0.005, choose a one-page limit. Browser charge estimate is shown for each limit. Existing budgets apply. Retrying an unfinished saved task retrieves it without submitting again. Popup dismissal is a provider option, not proof of consent or a user journey.</p>
            <button type="submit" class="button"<?= $result?->isQueued() ? ' disabled' : '' ?>><?= $savedTask ? 'Retry saved check' : 'Run paid rendered check' ?></button>
            <?php if ($savedTask): ?><button type="submit" name="fresh" value="1" class="button button-ghost">Start new paid check</button><?php endif; ?>
        </form>
    <?php endif; ?>
    <?php if ($result !== null): ?>
        <p><strong><?= $view->e($result->isQueued() ? 'Waiting for cron / rendering' : ($result->status === 'failed' ? 'Check stopped' : 'Completed')) ?></strong> · requested <?= $view->e($result->requestedAt) ?> UTC</p>
        <?php if ($result->error !== null): ?><p class="notice notice-error"><?= $view->e($result->error) ?></p><?php endif; ?>
        <?php if ($result->progressNotice() !== null): ?><p class="notice"><?= $view->e($result->progressNotice()) ?></p><?php endif; ?>
    <?php endif; ?>
    <?php $savedEvidence = $evidence ?? $result; ?>
    <?php foreach ($savedEvidence?->items ?? [] as $row): ?>
    <?php if (($row['mode'] ?? null) === 'lighthouse'): ?>
        <h3>Saved Lighthouse mobile diagnostic</h3><p><?= $view->e($row['url'] ?? '') ?> · <?= $view->e($savedEvidence?->fetchedAt ?? '') ?> UTC · version <?= $view->e($row['version'] ?? 'Unknown') ?></p>
        <dl><?php foreach ($row['categories'] ?? [] as $category => $score): ?><dt><?= $view->e($category) ?></dt><dd><?= $score !== null ? $view->e(round($score * 100)) . ' / 100' : 'Unavailable' ?></dd><?php endforeach; ?><?php foreach ($row['metrics'] ?? [] as $metric => $value): ?><dt><?= $view->e($metric) ?></dt><dd><?= $view->e($value ?? 'Unavailable') ?></dd><?php endforeach; ?></dl>
        <p class="muted small">Lab navigation run. Total blocking time is not INP; these scores are not a Core Web Vitals field-data pass.</p><ul><?php foreach ($row['lighthouse_findings'] ?? [] as $finding): ?><li><?= $view->e($finding['title']) ?> <?= $view->e($finding['display'] ?? '') ?></li><?php endforeach; ?></ul>
    <?php elseif (is_array($row)): ?>
        <h3>Saved browser evidence</h3>
        <p><?= $view->e($row['url'] ?? '') ?> · fetched <?= $view->e($savedEvidence?->fetchedAt ?? '') ?> UTC<?= $savedEvidence?->status !== 'done' ? ' · previous completed result' : '' ?></p>
        <dl>
            <dt>Rendered title</dt><dd><?= $view->e($row['title'] ?? 'Unavailable') ?></dd>
            <dt>Rendered description</dt><dd><?= $view->e($row['description'] ?? 'Unavailable') ?></dd>
            <dt>Reference base URL</dt><dd><?= $view->e($row['reference_base_url'] ?? 'Unavailable') ?></dd>
            <dt>H1 sample</dt><dd><?= $view->e(implode(' · ', $row['h1s'] ?? [])) ?></dd>
            <dt>Links sampled</dt><dd><?= $view->e(count($row['links'] ?? [])) ?> (up to 100)</dd>
            <dt>Images missing alt</dt><dd><?= $view->e($row['missing_alt'] ?? 'Unavailable') ?> (first 100 images)</dd>
            <dt>Lab LCP</dt><dd><?= isset($row['lcp_ms']) ? $view->e(number_format($row['lcp_ms'])) . ' ms' : 'Unavailable' ?></dd>
            <dt>Lab CLS</dt><dd><?= $view->e($row['cls'] ?? 'Unavailable') ?></dd>
        </dl>
        <p class="small muted">Lab measurements are a single browser run, not Google’s p75 field data or a Core Web Vitals pass/fail assessment. INP is not supplied by this check. Cookie consent, scrolling and later user interactions can change what loads.</p>
        <?php if (($row['findings'] ?? []) !== []): ?>
            <ul><?php foreach ($row['findings'] as $finding): ?><li><?= $view->e($finding) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <details><summary>Browser links (<?= $view->e(count($row['links'] ?? [])) ?>)</summary>
            <ul><?php foreach ($row['links'] ?? [] as $link): ?><li><?= $view->e($link) ?></li><?php endforeach; ?></ul>
        </details>
        <details><summary>Resource evidence (<?= $view->e(count($row['resources'] ?? [])) ?>)</summary>
            <p class="small muted">Up to 60 loaded resource entries, CSS backgrounds from the first 100 DOM elements (including ::before and ::after) and the first 40 images; capped at 100 references. css_before_background and css_after_background identify pseudo-element declarations. Declared CSS/image references are not proof of visibility or successful loading. A full sample can omit later references.</p>
            <ul><?php foreach ($row['resources'] ?? [] as $resource): ?><li><?= $view->e($resource['kind']) ?> · <?= $view->e($resource['url']) ?></li><?php endforeach; ?></ul>
        </details>
        <details><summary>Lazy-image candidates<?= is_array($row['lazy_images'] ?? null) ? ' (' . $view->e(count($row['lazy_images'])) . ')' : '' ?></summary>
            <p class="small muted">Up to 40 declared URLs from data-src, data-original and data-lazy-src on the first 40 images, including images with placeholder sources. These candidates are not proof of loading or HTTP success. Responsive declarations have their own sample below. No scrolling or clicking is performed.</p>
            <?php if (!is_array($row['lazy_images'] ?? null)): ?><p class="muted">Unavailable in this saved snapshot. Run a new check to collect this sample.</p>
            <?php elseif ($row['lazy_images'] === []): ?><p class="muted">No candidates in this sample.</p>
            <?php else: ?><ul><?php foreach ($row['lazy_images'] as $candidate): ?><li><?= $view->e($candidate) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </details>
        <details><summary>Responsive-image candidates<?= is_array($row['responsive_images'] ?? null) ? ' (' . $view->e(count($row['responsive_images'])) . ')' : '' ?></summary>
            <p class="small muted">Up to 40 distinct URL declarations from srcset and data-srcset: first 20 images/picture sources, up to 20 attributes of at most 2,048 characters. Longer attributes are omitted. Descriptors, media conditions and image-format support are not evaluated; these candidates are not proof of browser selection, loading or HTTP success.</p>
            <?php if (!is_array($row['responsive_images'] ?? null)): ?><p class="muted">Unavailable in this saved snapshot. Run a new check to collect responsive declarations.</p>
            <?php elseif ($row['responsive_images'] === []): ?><p class="muted">No responsive candidates in this sample.</p>
            <?php else: ?><ul><?php foreach ($row['responsive_images'] as $candidate): ?><li><?= $view->e($candidate) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </details>
        <?php foreach (['browser_resources' => 'Provider resource statuses', 'browser_waterfall' => 'Provider waterfall'] as $evidenceKey => $evidenceLabel): ?>
        <details><summary><?= $view->e($evidenceLabel) ?> (<?= $view->e(count($row[$evidenceKey] ?? [])) ?>)</summary><div class="table-scroll"><table><thead><tr><th>Resource</th><th>Kind</th><th>HTTP status</th><th>Start ms</th><th>End ms</th><th>Duration ms</th></tr></thead><tbody><?php foreach ($row[$evidenceKey] ?? [] as $resource): ?><tr><td><?= $view->e($resource['url']) ?></td><td><?= $view->e($resource['kind']) ?></td><td><?= $view->e($resource['status'] ?? 'Unavailable') ?></td><td><?= $view->e($resource['fetch_start'] ?? 'Unavailable') ?></td><td><?= $view->e($resource['fetch_end'] ?? 'Unavailable') ?></td><td><?= $view->e($resource['duration_time'] ?? 'Unavailable') ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php endforeach; ?>
    <p class="small muted">Crawl coverage is bounded by the selected page limit, 10,000 provider resource rows and 6 MB of stored evidence. Waterfall lists up to 1,000 resources per page. Provider CSS resources include what its browser loads; inaccessible stylesheets, lazy loading, scrolling and later interactions may require a separate browser journey.</p>
    <?php if (($history ?? []) !== []): ?><details><summary>Previous diagnostics (latest five)</summary><?php foreach ($history as $snapshot): ?><p><a href="/projects/<?= $view->e($project->publicId) ?>/site-audit?rendered_snapshot=<?= $view->e($snapshot->params['_request_id'] ?? '') ?>"><?= $view->e($snapshot->fetchedAt ?? '') ?> UTC · <?= $view->e($snapshot->params['mode'] ?? 'browser') ?> · <?= $view->e(count($snapshot->items)) ?> saved pages · <?= $view->e($snapshot->params['url'] ?? '') ?></a></p><?php endforeach; ?></details><?php endif; ?>
</section>
