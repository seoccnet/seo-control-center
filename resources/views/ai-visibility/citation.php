<?php
/**
 * Citation readiness of the top pages (0.43.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{auditDate: ?string, pages: list<array{url: string, clicks: int, crawled: bool, article: bool, checks: list<array{key: string, label: string, tested: string, hint: string, passed: ?bool}>, score: array{passed: int, known: int, unknown: int}}>} $citation
 */
use SeoControlCenter\Domain\AiVisibility\CitationReadiness;
use SeoControlCenter\Domain\Opportunities\Playbook;

$base = '/projects/' . $project->publicId;
$tested = static fn(string $level): string => Playbook::TESTED[$level][0] ?? Playbook::TESTED['guidance'][0];
?>
<section class="card" id="citation-readiness">
    <h2>Citation readiness of your top pages</h2>
    <p class="muted small">What your most-clicked pages of the last 28 days offer AI answers to quote, from the latest site audit<?= $citation['auditDate'] !== null ? ' (' . $view->e($citation['auditDate']) . ')' : '' ?>. Each check says how well published tests back it.</p>
    <?php if ($citation['pages'] === []): ?>
        <p class="muted">No Search Console pages yet. The list appears once Search Console data is imported.</p>
    <?php else: ?>
        <?php if ($citation['auditDate'] === null): ?>
            <p class="muted small">No completed site audit yet: run one under <a href="<?= $view->e($base) ?>/site-audit">Site audit</a> to fill in the checks.</p>
        <?php endif; ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Page</th><th scope="col" class="num">Clicks</th><th scope="col" class="num">Ready</th><th scope="col">Missing</th></tr></thead>
                <tbody>
                    <?php foreach ($citation['pages'] as $row): ?>
                        <?php $missing = array_values(array_filter($row['checks'], static fn(array $c): bool => $c['passed'] === false)); ?>
                        <tr>
                            <td class="small"><?= $view->e((string) (parse_url($row['url'], PHP_URL_PATH) ?: $row['url'])) ?></td>
                            <td class="num"><?= $view->e(number_format($row['clicks'])) ?></td>
                            <td class="num"><?= $row['crawled'] && $row['score']['known'] > 0 ? $view->e($row['score']['passed'] . ' / ' . $row['score']['known']) : '—' ?></td>
                            <td class="small">
                                <?php if (!$row['crawled']): ?>
                                    <span class="muted">Not in the latest site audit</span>
                                <?php elseif ($missing === []): ?>
                                    <span class="muted"><?= $row['score']['unknown'] > 0 ? 'Nothing missing so far' : 'Nothing missing' ?></span>
                                <?php else: ?>
                                    <?= implode(', ', array_map(static fn(array $c): string => '<span title="' . $view->e($c['hint']) . '">' . $view->e($c['label']) . '</span>', $missing)) ?>
                                <?php endif; ?>
                                <?php if ($row['crawled'] && $row['score']['unknown'] > 0): ?>
                                    <span class="muted">· <?= $view->e($row['score']['unknown'] === 1 ? '1 check' : $row['score']['unknown'] . ' checks') ?> after the next site audit</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <details>
        <summary class="small">What is checked</summary>
        <ul class="small">
            <?php foreach (CitationReadiness::CHECKS as $key => $check): ?>
                <li><strong><?= $view->e($check['label']) ?></strong> <span class="badge" title="<?= $view->e(Playbook::TESTED[$check['tested']][1] ?? '') ?>"><?= $view->e($tested($check['tested'])) ?></span> <?= $view->e($check['hint']) ?><?= in_array($key, CitationReadiness::ARTICLE_ONLY, true) ? ' <span class="muted">Articles only.</span>' : '' ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="muted small">The author and source checks count only for articles: pages with Article structured data or og:type article, or in a blog, guide or news folder. Product, category and home pages are not expected to name an author or cite sources. Pages crawled before 0.43.0 show only the checks the audit recorded then, until the next site audit.</p>
    </details>
</section>
