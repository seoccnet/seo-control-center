<?php

declare(strict_types=1);

use SeoControlCenter\Domain\BotLogs\LogLineParser;

/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<SeoControlCenter\Modules\BotLogs\BotLogImport> $imports
 * @var ?SeoControlCenter\Modules\BotLogs\BotLogImport $selected
 * @var array{bots: array<string, array{hits: int, paths: int, ok: int, redirects: int, client_errors: int, server_errors: int, with_query: int}>, bot: string, top: list<array{path: string, status: int, hits: int}>, errors: list<array{path: string, status: int, hits: int}>, redirects: list<array{path: string, status: int, hits: int}>, parameters: list<array{path: string, status: int, hits: int}>, missed: list<array{url: string, clicks: int}>, missedChecked: int}|null $report
 * @var bool $canEdit
 * @var int $limitMb
 */

$base = '/projects/' . $project->publicId . '/bot-logs';
$view->extend('layouts/app', ['title' => 'Bot logs · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'bot-logs']);
$share = static fn(int $part, int $whole): string => $whole > 0 ? number_format(100 * $part / $whole, 1) . '%' : '–';
$table = static function (string $id, string $title, string $empty, array $rows) use ($view): string {
    $html = '<section class="card" id="' . $view->e($id) . '" aria-labelledby="' . $view->e($id) . '-heading"><h2 id="' . $view->e($id) . '-heading">' . $view->e($title) . '</h2>';
    if ($rows === []) {
        return $html . '<p class="muted">' . $view->e($empty) . '</p></section>';
    }
    $html .= '<div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Path</th><th scope="col">Status</th><th scope="col">Requests</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td class="brief-url">' . $view->e($row['path']) . '</td><td>' . $view->e((string) $row['status']) . '</td><td>' . $view->e(number_format($row['hits'])) . '</td></tr>';
    }

    return $html . '</tbody></table></div></section>';
};
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow">Improve · Bot logs</p><h2>See what search and AI crawlers actually request</h2><p class="muted">Upload your server’s access log to find crawl spent on errors, redirects and parameter URLs, and pages that earn clicks but were not crawled.</p></div></header>

<?php if ($canEdit): ?>
<section class="card" aria-labelledby="bot-log-upload-heading">
    <h2 id="bot-log-upload-heading">Upload an access log</h2>
    <form method="post" action="<?= $view->e($base) ?>" enctype="multipart/form-data" class="stack">
        <?= $view->csrfField() ?>
        <div class="field"><label for="bot-log-file">Access log (plain text or .gz, up to <?= $view->e((string) $limitMb) ?> MB)</label><input id="bot-log-file" name="log" type="file" required accept=".log,.txt,.gz,text/plain,application/gzip"></div>
        <button class="button button-primary">Upload and analyse</button>
    </form>
    <p class="muted small">In cPanel, open <strong>Metrics → Raw Access</strong> and download the log of this site; other hosts offer it under logs or statistics. Common and Combined Log Format (Apache, nginx, LiteSpeed) are read. SEOcc keeps only the requests of search and AI crawlers, counted per path and status code, and deletes the file once it is read. Visitor IP addresses are never stored.</p>
    <p class="muted small">Crawlers are recognised by their user agent, which anyone can copy, so a share of “Googlebot” requests may come from other tools. The newest <?= $view->e((string) SeoControlCenter\Modules\BotLogs\BotLogService::KEEP) ?> logs of a project are kept.</p>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="bot-log-list-heading">
    <h2 id="bot-log-list-heading">Uploaded logs</h2>
    <?php if ($imports === []): ?>
    <div class="empty-state"><h3>No logs yet</h3><p>Upload an access log covering a week or more for a useful picture.</p></div>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">File</th><th scope="col">Period (UTC)</th><th scope="col">Lines</th><th scope="col">Crawler requests</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($imports as $import): ?>
            <tr<?= $selected?->id === $import->id ? ' aria-current="true"' : '' ?>>
                <td><?php if ($import->status === 'done'): ?><a href="<?= $view->e($base . '?log=' . $import->publicId) ?>"><?= $view->e($import->fileName) ?></a><?php else: ?><?= $view->e($import->fileName) ?><?php endif; ?></td>
                <td><?= $import->firstSeen !== null ? $view->e(substr($import->firstSeen, 0, 10) . ' – ' . substr((string) $import->lastSeen, 0, 10)) : '–' ?></td>
                <td><?= $view->e(number_format($import->linesRead)) ?></td>
                <td><?= $view->e(number_format($import->botLines)) ?></td>
                <td><?= $view->e(match ($import->status) { 'done' => 'Ready', 'failed' => 'Failed', 'running' => sprintf('Reading (%s%%)', $import->fileBytes > 0 ? min(99, (int) floor(100 * $import->byteOffset / $import->fileBytes)) : 0), default => 'Queued' }) ?><?php if ($import->error !== null): ?><br><span class="muted small"><?= $view->e($import->error) ?></span><?php endif; ?></td>
                <td><?php if ($canEdit): ?><form method="post" action="<?= $view->e($base . '/' . $import->publicId . '/delete') ?>"><?= $view->csrfField() ?><button class="button button-small button-ghost" data-confirm="Delete this log report?">Delete</button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php if ($selected !== null && $report !== null): ?>
<section class="card" aria-labelledby="bot-log-bots-heading">
    <h2 id="bot-log-bots-heading">Crawlers in <?= $view->e($selected->fileName) ?></h2>
    <?php if ($report['bots'] === []): ?>
    <p class="muted">The log has no requests from known search or AI crawlers. Check that it is the access log of this site in a standard format.</p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Crawler</th><th scope="col">Requests</th><th scope="col">Distinct paths</th><th scope="col">Success (2xx)</th><th scope="col">Redirects (3xx)</th><th scope="col">Errors (4xx)</th><th scope="col">Server errors (5xx)</th><th scope="col">With parameters</th></tr></thead>
        <tbody>
        <?php foreach ($report['bots'] as $key => $t): ?>
            <tr><td><a href="<?= $view->e($base . '?' . http_build_query(['log' => $selected->publicId, 'bot' => $key])) ?>"<?= $key === $report['bot'] ? ' aria-current="true"' : '' ?>><?= $view->e(LogLineParser::label($key)) ?></a></td><td><?= $view->e(number_format($t['hits'])) ?></td><td><?= $view->e(number_format($t['paths'])) ?></td><td><?= $view->e($share($t['ok'], $t['hits'])) ?></td><td><?= $view->e($share($t['redirects'], $t['hits'])) ?></td><td><?= $view->e($share($t['client_errors'], $t['hits'])) ?></td><td><?= $view->e($share($t['server_errors'], $t['hits'])) ?></td><td><?= $view->e($share($t['with_query'], $t['hits'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($selected->droppedHits > 0): ?><p class="muted small"><?= $view->e(number_format($selected->droppedHits)) ?> requests to further paths were counted in the totals of the file but not kept per path (limit <?= $view->e(number_format(SeoControlCenter\Modules\BotLogs\BotLogService::MAX_ROWS)) ?>).</p><?php endif; ?>
    <?php endif; ?>
</section>
<?php if ($report['bots'] !== []): ?>
<p class="muted">Details below are for <strong><?= $view->e(LogLineParser::label($report['bot'])) ?></strong>. Choose another crawler in the table above.</p>
<section class="card" id="bot-log-missed" aria-labelledby="bot-log-missed-heading">
    <h2 id="bot-log-missed-heading">Pages with clicks this crawler did not request</h2>
    <?php if ($report['missedChecked'] === 0): ?>
    <p class="muted">Connect Search Console to compare the log with the pages that earn clicks.</p>
    <?php elseif ($report['missed'] === []): ?>
    <p class="muted">All <?= $view->e(number_format($report['missedChecked'])) ?> pages with the most Google clicks in the 28 days up to the log’s end were requested at least once.</p>
    <?php else: ?>
    <p class="muted small">Of the <?= $view->e(number_format($report['missedChecked'])) ?> pages with the most Google clicks in the 28 days up to the log’s end, these were not requested in the log. A short log can miss pages that are crawled less often; check their internal links and sitemap entry.</p>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Page</th><th scope="col">Google clicks, 28 days</th></tr></thead><tbody>
    <?php foreach ($report['missed'] as $m): ?><tr><td class="brief-url"><?= $view->e($m['url']) ?></td><td><?= $view->e(number_format($m['clicks'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?= $table('bot-log-errors', 'Errors crawlers ran into', 'No 4xx or 5xx responses to this crawler.', $report['errors']) ?>
<?= $table('bot-log-parameters', 'Parameter URLs crawled', 'This crawler requested no URLs with parameters.', $report['parameters']) ?>
<?= $table('bot-log-redirects', 'Redirects crawled', 'This crawler hit no redirects.', $report['redirects']) ?>
<?= $table('bot-log-top', 'Most requested paths', 'No requests.', $report['top']) ?>
<?php endif; ?>
<?php elseif ($selected !== null): ?>
<section class="card"><p class="muted"><?= $view->e($selected->status === 'failed' ? 'This log could not be read.' : 'This log is still being read. Reload in a minute.') ?></p></section>
<?php endif; ?>
</div>
