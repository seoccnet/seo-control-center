<?php

declare(strict_types=1);

/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Modules\SiteHealth\SiteHealthState $state
 * @var list<array{id: string, kind: string, title: string, detail: string, created_at: string}> $events
 * @var bool $canEdit
 * @var ?int $sslDays days until the certificate expires
 * @var ?int $domainDays days until the domain expires
 */

$base = '/projects/' . $project->publicId . '/site-health';
$view->extend('layouts/app', ['title' => 'Site health · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-health']);
$badge = static fn(string $tone, string $text): string => '<span class="badge badge-' . $tone . '">' . htmlspecialchars($text, ENT_QUOTES) . '</span>';
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow">Visibility · Site health</p><h2>Catch outages and expiring renewals before they cost traffic</h2><p class="muted">SEOcc checks <?= $view->e($project->primaryDomain) ?> every 15 minutes, and its certificate, robots.txt and sitemap once a day. Problems are sent as alerts to everyone who wants them.</p></div></header>

<?php if (!$state->enabled): ?>
<section class="card"><p>Site health monitoring is <strong>off</strong> for this project.</p>
<?php if ($canEdit): ?><form method="post" action="<?= $view->e($base . '/settings') ?>"><?= $view->csrfField() ?><input type="hidden" name="enabled" value="1"><button class="button button-primary">Turn monitoring on</button></form><?php endif; ?>
</section>
<?php else: ?>
<section class="card" aria-labelledby="health-status-heading">
    <div class="sync-row"><h2 id="health-status-heading">Current status</h2>
    <?php if ($canEdit): ?><form method="post" action="<?= $view->e($base . '/check') ?>"><?= $view->csrfField() ?><button class="button button-small">Check now</button></form><?php endif; ?></div>
    <dl class="indexing-evidence">
        <dt>Uptime</dt>
        <dd><?php if ($state->uptimeCheckedAt === null): ?><span class="muted">Not checked yet; the first check runs within 15 minutes.</span>
        <?php elseif ($state->uptimeStatus === 'down'): ?><?= $badge('error', 'Down') ?> since <?= $view->e((string) $state->downSince) ?> UTC · <?= $view->e((string) $state->uptimeError) ?>
        <?php else: ?><?= $badge('ok', 'Up') ?> HTTP <?= $view->e((string) $state->uptimeCode) ?><?= $state->uptimeMs !== null ? ' in ' . $view->e(number_format($state->uptimeMs)) . ' ms' : '' ?><?= $state->failures > 0 ? ' · <span class="muted">the last check failed (' . $view->e((string) $state->uptimeError) . '); one more counts as down</span>' : '' ?>
        <?php endif; ?><?= $state->uptimeCheckedAt !== null ? '<br><span class="muted small">Checked ' . $view->e($state->uptimeCheckedAt) . ' UTC</span>' : '' ?></dd>

        <dt>TLS certificate</dt>
        <dd><?php if ($state->dailyCheckedAt === null): ?><span class="muted">Not checked yet.</span>
        <?php elseif ($state->sslError !== null): ?><?= $badge('error', 'Problem') ?> <?= $view->e($state->sslError) ?>
        <?php elseif ($sslDays !== null): ?><?= $badge($sslDays <= 14 ? 'warning' : 'ok', $sslDays < 0 ? 'Expired' : 'Valid') ?> expires <?= $view->e(substr((string) $state->sslExpiresAt, 0, 10)) ?> (in <?= $view->e((string) $sslDays) ?> days)<?= $state->sslIssuer !== null ? ' · issued by ' . $view->e($state->sslIssuer) : '' ?>
        <?php endif; ?></dd>

        <dt>Domain registration</dt>
        <dd><?php if ($state->domainCheckedAt === null): ?><span class="muted">Not checked yet.</span>
        <?php elseif ($domainDays !== null): ?><?= $badge($domainDays <= 30 ? 'warning' : 'ok', $domainDays < 0 ? 'Expired' : 'Registered') ?> <?= $view->e((string) $state->domainName) ?> expires <?= $view->e(substr((string) $state->domainExpiresAt, 0, 10)) ?> (in <?= $view->e((string) $domainDays) ?> days)<?= $state->domainRegistrar !== null ? ' · ' . $view->e($state->domainRegistrar) : '' ?>
        <?php else: ?><span class="muted"><?= $view->e((string) $state->domainName) ?>: <?= $view->e((string) $state->domainError) ?></span>
        <?php endif; ?></dd>

        <dt>robots.txt</dt>
        <dd><?php if ($state->robotsStatus === null): ?><span class="muted">Not checked yet.</span>
        <?php else: ?>HTTP <?= $view->e((string) $state->robotsStatus) ?><?= $state->robotsChangedAt !== null ? ' · last changed ' . $view->e($state->robotsChangedAt) . ' UTC' : ' · unchanged since monitoring began' ?>
        <?php if (($state->robotsText ?? '') !== ''): ?><details><summary class="small">Current robots.txt</summary><pre class="small"><?= $view->e((string) $state->robotsText) ?></pre></details><?php endif; ?>
        <?php endif; ?></dd>

        <dt>Sitemap</dt>
        <dd><?php if ($state->sitemapUrls === null): ?><span class="muted">Not checked yet.</span>
        <?php else: ?><?= $view->e(number_format($state->sitemapUrls)) ?> page URLs<?= $state->sitemapUrls >= SeoControlCenter\Modules\SiteHealth\SiteHealthService::SITEMAP_LIMIT ? ' or more' : '' ?><?= $state->sitemapChangedAt !== null ? ' · count last changed ' . $view->e($state->sitemapChangedAt) . ' UTC' : '' ?>
        <?php endif; ?></dd>
    </dl>
    <p class="muted small">Down means two checks in a row got no answer or a server error (5xx). Warnings: certificates 14 and 3 days before they expire, domains 30 and 7 days before, robots.txt when it changes, and the sitemap when it loses at least 10% of its pages. Domain dates come from the registry's RDAP service; some country registries (such as .de) publish none.</p>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="health-events-heading">
    <h2 id="health-events-heading">History</h2>
    <?php if ($events === []): ?>
    <p class="muted">No events yet. Outages, recoveries, expiry warnings and changes to robots.txt and the sitemap appear here.</p>
    <?php else: ?>
    <ul class="brief-list">
        <?php foreach ($events as $event): ?>
        <li class="brief-list-item" id="event-<?= $view->e($event['id']) ?>"><div><h3><?= $view->e($event['title']) ?></h3><p class="muted small"><?= $view->e($event['created_at']) ?> UTC</p><p class="health-detail"><?= $view->e($event['detail']) ?></p></div></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<?php if ($canEdit && $state->enabled): ?>
<section class="card"><form method="post" action="<?= $view->e($base . '/settings') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="enabled" value="0"><button class="button button-small button-ghost">Turn monitoring off for this project</button></form></section>
<?php endif; ?>
</div>
