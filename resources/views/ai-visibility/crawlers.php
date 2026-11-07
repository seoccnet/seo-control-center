<?php
/**
 * AI crawler access (0.43.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck|null $check
 * @var bool $canRun
 */
use SeoControlCenter\Domain\AiVisibility\AiCrawlers;
use SeoControlCenter\Domain\Opportunities\Playbook;

$action = '/projects/' . $project->publicId . '/ai-visibility/crawlers/check';
$badge = static fn(string $state): string => match ($state) {
    AiCrawlers::ALLOWED => '<span class="badge badge-ok">Allowed</span>',
    AiCrawlers::PARTIAL => '<span class="badge badge-warning">Some top pages blocked</span>',
    default => '<span class="badge badge-error">Blocked</span>',
};
$rows = static function (array $agents) use ($view, $badge): string {
    $html = '';
    foreach ($agents as $agent) {
        $info = AiCrawlers::AGENTS[$agent['token']];
        $html .= '<tr><td><code>' . $view->e($agent['token']) . '</code> <span class="muted small">' . $view->e($info['name']) . '</span></td>'
            . '<td class="small">' . $view->e($info['purpose']) . '</td>'
            . '<td>' . $badge($agent['state']) . ($agent['blocked'] !== [] && $agent['state'] === AiCrawlers::PARTIAL ? '<div class="small muted">' . $view->e(implode(', ', array_slice($agent['blocked'], 0, 3))) . (count($agent['blocked']) > 3 ? $view->e(' +' . (count($agent['blocked']) - 3)) : '') . '</div>' : '') . '</td>'
            . '<td class="small muted">' . ($agent['named'] ? 'Its own group' : 'The * rules') . '</td></tr>';
    }

    return $html;
};
?>
<section class="card" id="ai-crawlers">
    <div class="card-header">
        <h2>AI crawler access</h2>
        <?php if ($canRun): ?>
            <form method="post" action="<?= $view->e($action) ?>">
                <?= $view->csrfField() ?>
                <button type="submit" class="button button-ghost button-small"><?= $check === null ? 'Check now' : 'Check again' ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($check === null): ?>
        <p>AI search tools can only show and cite pages their crawlers may fetch. This check reads your site’s robots.txt and llms.txt and shows which AI crawlers are let in, on the home page and on your top Search Console pages.</p>
        <p class="muted small">Free: only your own site is read. It runs weekly by itself; the first check runs within a day, or now with the button.</p>
    <?php else: ?>
        <p class="muted small">Checked <?= $view->e(substr($check->checkedAt, 0, 16)) ?> UTC · <a href="<?= $view->e($check->robotsUrl) ?>" rel="noopener noreferrer" target="_blank">robots.txt</a>
            <?= match ($check->robotsState) {
                'ok' => '· read',
                'missing' => '· not found (HTTP ' . $view->e((string) $check->robotsStatus) . '), so every crawler is allowed',
                default => '',
            } ?></p>
        <?php if ($check->robotsState === 'unreachable'): ?>
            <p class="notice notice-warning">robots.txt could not be read<?= $check->robotsStatus !== null ? ' (HTTP ' . $view->e((string) $check->robotsStatus) . ')' : '' ?>. Crawlers that cannot read it may stop fetching the site until it answers again. Check that it loads in a browser.</p>
        <?php else: ?>
            <?php $blocked = $check->blockedSearch(); ?>
            <?php if ($blocked !== []): ?>
                <p class="notice notice-warning">robots.txt keeps <?= $view->e(implode(', ', array_map(static fn(array $a): string => $a['token'], $blocked))) ?> out<?= count(array_filter($blocked, static fn(array $a): bool => $a['state'] === AiCrawlers::PARTIAL)) === count($blocked) ? ' of some of your top pages' : '' ?>. Pages these crawlers may not fetch cannot be shown or cited in their answers.</p>
            <?php endif; ?>
            <h3>Search and answer crawlers</h3>
            <p class="muted small">They fetch pages so an assistant or search engine can show and cite them. Blocking them keeps your pages out of those answers.</p>
            <div class="table-scroll">
                <table class="table table-dense">
                    <thead><tr><th scope="col">Crawler</th><th scope="col">Used for</th><th scope="col">robots.txt</th><th scope="col">Rule used</th></tr></thead>
                    <tbody><?= $rows($check->ofKind(AiCrawlers::SEARCH)) ?></tbody>
                </table>
            </div>
            <h3>Training crawlers</h3>
            <p class="muted small">Your choice: they collect text for model training. Blocking them does not stop the search crawlers above from citing your pages.</p>
            <div class="table-scroll">
                <table class="table table-dense">
                    <thead><tr><th scope="col">Crawler</th><th scope="col">Used for</th><th scope="col">robots.txt</th><th scope="col">Rule used</th></tr></thead>
                    <tbody><?= $rows($check->ofKind(AiCrawlers::TRAINING)) ?></tbody>
                </table>
            </div>
        <?php endif; ?>
        <h3>llms.txt <span class="badge" title="<?= $view->e(Playbook::TESTED['early'][1]) ?>"><?= $view->e(Playbook::TESTED['early'][0]) ?></span></h3>
        <p class="small"><?= match ($check->llmsState) {
            'found' => 'Found (' . $view->e(number_format((float) $check->llmsBytes)) . ' bytes).',
            'missing' => 'Not found.',
            default => 'Could not be read' . ($check->llmsStatus !== null ? ' (HTTP ' . $view->e((string) $check->llmsStatus) . ')' : '') . '.',
        } ?> An llms.txt file is a short Markdown guide to your most useful pages for AI tools. It is optional: no published test has measured an effect on AI answers yet, so treat it as a low-cost experiment, not a fix.</p>
    <?php endif; ?>
</section>
