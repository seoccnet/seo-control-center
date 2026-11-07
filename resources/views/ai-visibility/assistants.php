<?php
/**
 * AI visibility → AI assistants: mentions of the project and its competitors
 * in ChatGPT and Google's AI answers, and the questions that mention it.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{mentionsResult: ?SeoControlCenter\Modules\Research\ResearchResult, answersResult: ?SeoControlCenter\Modules\Research\ResearchResult, canRun: bool, canEdit: bool, available: bool, featureOn: bool, costs: array{llm: float, llm_prompts: float}, locale: SeoControlCenter\Domain\Keywords\KeywordLocale, weekly: bool, history: list<array{date: string, mentions: array<string, array<string, int>>}>} $assistants
 * @var string $ownDomain
 */
use SeoControlCenter\Domain\Research\AiMentionsProvider;
use SeoControlCenter\Modules\Research\ResearchResult;

$self = '/projects/' . $project->publicId . '/keywords/research/fetch';
$canFetch = $assistants['canRun'] && $assistants['available'] && $assistants['featureOn'];
$locale = $assistants['locale'];
$platformLabels = [AiMentionsProvider::CHATGPT => 'ChatGPT', AiMentionsProvider::GOOGLE => "Google\u{00A0}AI"];
$mentionsResult = $assistants['mentionsResult'];
$answersResult = $assistants['answersResult'];
$queued = ($mentionsResult?->isQueued() ?? false) || ($answersResult?->isQueued() ?? false);

$button = static function (string $kind, string $label, float $cost) use ($view, $self, $locale, $canFetch): string {
    if (!$canFetch) {
        return '';
    }

    return '<form method="post" action="' . $view->e($self) . '" class="inline-form">' . $view->csrfField()
        . '<input type="hidden" name="kind" value="' . $view->e($kind) . '">'
        . '<input type="hidden" name="location" value="' . $view->e($locale->locationCode) . '">'
        . '<input type="hidden" name="language" value="' . $view->e($locale->languageCode) . '">'
        . '<button type="submit" class="button button-small">' . $view->e($label) . '</button>'
        . ' <span class="muted small">about ' . $view->e('$' . number_format($cost, 2)) . '</span></form>';
};
$status = static function (?ResearchResult $result) use ($view): string {
    if ($result === null) {
        return '<p class="muted small">Not checked yet.</p>';
    }
    $line = $result->fetchedAt !== null ? 'Checked ' . $result->fetchedAt . ' UTC' : '';
    if ($result->isQueued()) {
        $line = 'Queued ' . $result->requestedAt . ' UTC; the result appears after the next cron run.' . ($line !== '' ? ' Showing the previous check: ' . $line : '');
    }
    $html = $line !== '' ? '<p class="muted small">' . $view->e($line) . '</p>' : '';
    if ($result->status === 'failed' && $result->error !== null) {
        $html .= '<p class="notice notice-error">The check failed: ' . $view->e($result->error) . '</p>';
    }

    if ($result->progressNotice() !== null) {
        $html .= '<p class="muted small">' . $view->e($result->progressNotice()) . '</p>';
    }
    return $html;
};

// Mentions per domain and platform.
$byDomain = [];
foreach ($mentionsResult?->mentions() ?? [] as $m) {
    $byDomain[$m->domain][$m->platform] = $m;
}
uksort($byDomain, static function (string $a, string $b) use ($byDomain): int {
    $total = static fn(array $row): int => array_sum(array_map(static fn($m): int => $m->mentions, $row));

    return [$total($byDomain[$b]), $a] <=> [$total($byDomain[$a]), $b];
});
?>
<section class="card" id="assistants"<?= $queued ? ' data-refresh-seconds="15"' : '' ?>>
    <div class="card-header">
        <h2>AI assistants</h2>
        <?= $button('llm', 'Check mentions', $assistants['costs']['llm']) ?>
    </div>
    <p class="muted small">How often ChatGPT and Google's AI answers (AI Overviews and AI Mode) mention or cite your domain and your competitors, from DataForSEO's database of AI answers. AI search volume is the estimated monthly number of questions the mentions come from. ChatGPT data covers the United States in English; Google AI uses <?= $view->e($locale->label()) ?>.</p>
    <?php if (!$assistants['available']): ?>
        <p class="notice notice-warning"><a href="/integrations/dataforseo">Connect DataForSEO</a> to check AI mentions. Stored results stay readable.</p>
    <?php elseif (!$assistants['featureOn']): ?>
        <p class="notice notice-warning">Keyword data, which AI mentions are part of, is turned off on this installation. Stored results stay readable.</p>
    <?php endif; ?>
    <?= $status($mentionsResult) ?>
    <?php if ($byDomain !== []): ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Domain</th><?php foreach ($platformLabels as $label): ?><th scope="col" class="num"><?= $view->e($label) ?> mentions</th><?php endforeach; ?><th scope="col" class="num">AI search volume</th></tr></thead>
                <tbody>
                    <?php foreach ($byDomain as $domain => $row): ?>
                        <tr>
                            <td><?= $view->e($domain) ?><?= $domain === $ownDomain ? ' <span class="badge badge-ok">You</span>' : '' ?></td>
                            <?php foreach (array_keys($platformLabels) as $platform): ?>
                                <td class="num"><?= isset($row[$platform]) ? $view->e(number_format($row[$platform]->mentions)) : '—' ?></td>
                            <?php endforeach; ?>
                            <td class="num"><?php $volume = array_sum(array_map(static fn($m): int => $m->aiSearchVolume ?? 0, $row)); ?><?= $volume > 0 ? $view->e(number_format($volume)) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($mentionsResult !== null && $mentionsResult->fetchedAt !== null): ?>
        <p class="muted">No mentions found for these domains.</p>
    <?php endif; ?>

    <?php if (count($assistants['history']) > 1): ?>
        <h3>Your mentions over time</h3>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Checked</th><?php foreach ($platformLabels as $label): ?><th scope="col" class="num"><?= $view->e($label) ?></th><?php endforeach; ?><th scope="col" class="num">Total</th><th scope="col" class="num">Change</th></tr></thead>
                <tbody>
                    <?php foreach ($assistants['history'] as $i => $check): ?>
                        <?php
                        $own = $check['mentions'][$ownDomain] ?? [];
                        $total = array_sum($own);
                        $older = $assistants['history'][$i + 1] ?? null;
                        $before = $older !== null ? array_sum($older['mentions'][$ownDomain] ?? []) : null;
                        ?>
                        <tr>
                            <td><?= $view->e($check['date']) ?></td>
                            <?php foreach (array_keys($platformLabels) as $platform): ?>
                                <td class="num"><?= isset($own[$platform]) ? $view->e(number_format($own[$platform])) : '—' ?></td>
                            <?php endforeach; ?>
                            <td class="num"><?= $view->e(number_format($total)) ?></td>
                            <td class="num"><?php if ($before !== null && $before !== $total): ?><span class="delta <?= $total > $before ? 'delta-good' : 'delta-bad' ?>"><?= $view->e(sprintf('%+d', $total - $before)) ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($assistants['canEdit']): ?>
        <form method="post" action="/projects/<?= $view->e($project->publicId) ?>/ai-visibility/weekly" class="inline-form weekly-form">
            <?= $view->csrfField() ?>
            <input type="hidden" name="weekly" value="<?= $assistants['weekly'] ? '0' : '1' ?>">
            <span class="small muted"><?= $assistants['weekly'] ? 'Mentions are checked every week (about ' . $view->e('$' . number_format($assistants['costs']['llm'], 2)) . ' per check).' : 'Check mentions every week to build a history.' ?></span>
            <button type="submit" class="button button-small button-ghost"><?= $assistants['weekly'] ? 'Stop weekly check' : 'Check every week' ?></button>
        </form>
    <?php elseif ($assistants['weekly']): ?>
        <p class="small muted">Mentions are checked every week.</p>
    <?php endif; ?>

    <div class="card-header subsection-header">
        <h3>Questions that mention you</h3>
        <?= $button('llm_prompts', 'Find questions', $assistants['costs']['llm_prompts']) ?>
    </div>
    <p class="muted small">The most searched questions whose AI answers mention or cite <?= $view->e($ownDomain) ?>, with your place among the sources.</p>
    <?= $status($answersResult) ?>
    <?php if ($answersResult !== null && $answersResult->answers() !== []): ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Question</th><th scope="col">Platform</th><th scope="col" class="num">AI search volume</th><th scope="col" class="num">Your source</th><th scope="col">Also cited</th></tr></thead>
                <tbody>
                    <?php foreach ($answersResult->answers() as $a): ?>
                        <tr>
                            <td><?= $view->e($a->question) ?><?php if ($a->excerpt !== null): ?><br><span class="small muted"><?= $view->e($a->excerpt) ?></span><?php endif; ?></td>
                            <td class="small"><?= $view->e($platformLabels[$a->platform] ?? $a->platform) ?></td>
                            <td class="num"><?= $a->aiSearchVolume !== null ? $view->e(number_format($a->aiSearchVolume)) : '—' ?></td>
                            <td class="num"><?= $a->sourceRank !== null ? '<span class="badge badge-ok" title="' . $view->e($a->sourceUrl ?? '') . '">#' . $view->e($a->sourceRank) . '</span>' : '<span class="muted small" title="Mentioned in the answer, not cited as a source">mentioned</span>' ?></td>
                            <td class="small muted"><?= $view->e(implode(', ', array_slice(array_values(array_filter($a->sources, static fn(string $s): bool => $s !== $ownDomain && !str_ends_with($s, '.' . $ownDomain))), 0, 4))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($answersResult !== null && $answersResult->fetchedAt !== null): ?>
        <p class="muted">No questions found whose AI answers mention your domain.</p>
    <?php endif; ?>

    <div class="card-header subsection-header" id="ai-answers">
        <h3>Answers to your prompts</h3>
    </div>
    <p class="muted small">Send up to 10 prompts your customers might ask to Gemini and Perplexity with web search, and see whether the answers name your brand, cite your site or name your competitors. Each prompt is answered once per platform; AI answers change between runs, so this is a sample of today’s answers, not a measure of how often you are mentioned.</p>
    <?php $samples = $assistants['samplesResult']; ?>
    <?php if ($canFetch && $assistants['sampling']): ?>
        <form method="post" action="<?= $view->e($self) ?>">
            <?= $view->csrfField() ?>
            <input type="hidden" name="kind" value="llm_answers">
            <input type="hidden" name="location" value="<?= $view->e($locale->locationCode) ?>">
            <input type="hidden" name="language" value="<?= $view->e($locale->languageCode) ?>">
            <div class="field"><label for="ai-brand">Brand name to look for</label><input id="ai-brand" name="brand" type="text" maxlength="100" required value="<?= $view->e($assistants['brand']) ?>"></div>
            <div class="field"><label for="ai-prompts">Prompts, one per line (up to 10)</label><textarea id="ai-prompts" name="prompts" rows="5" maxlength="5500" required placeholder="Where can I buy organic tomato seeds online?"><?= $view->e(implode("\n", $assistants['prompts'])) ?></textarea></div>
            <p><button type="submit" class="button button-small">Check answers</button> <span class="muted small">at most <?= $view->e('$' . number_format($assistants['costs']['llm_answers_each'] * 2, 2)) ?> per prompt (Gemini and Perplexity); you pay what DataForSEO reports, usually less</span></p>
        </form>
    <?php endif; ?>
    <?= $status($samples) ?>
    <?php if ($samples !== null && $samples->samples() !== []): ?>
        <?php $answerLabels = ['gemini' => 'Gemini', 'perplexity' => 'Perplexity']; ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Prompt</th><th scope="col">Platform</th><th scope="col">Your brand</th><th scope="col">Your site cited</th><th scope="col">Competitors</th><th scope="col">Answer</th></tr></thead>
                <tbody>
                    <?php foreach ($samples->samples() as $s): ?>
                        <tr>
                            <td><?= $view->e($s['prompt']) ?></td>
                            <td class="small"><?= $view->e($answerLabels[$s['platform']] ?? $s['platform']) ?><br><span class="muted"><?= $view->e($s['model']) ?></span></td>
                            <td><?= $s['brand_mentioned'] ? '<span class="badge badge-ok">Named</span>' : '<span class="badge badge-warning">Not named</span>' ?></td>
                            <td><?= $s['own_cited'] ? '<span class="badge badge-ok">Cited</span>' : ($s['web_search'] ? '<span class="muted small">Not cited</span>' : '<span class="muted small">No web search</span>') ?></td>
                            <td class="small"><?= $s['competitors'] === [] ? '<span class="muted">None</span>' : $view->e(implode(', ', array_map(static fn(array $c): string => $c['domain'] . ($c['cited'] ? ' (cited)' : ''), $s['competitors']))) ?></td>
                            <td class="small"><details class="help-details"><summary>Read answer (<?= $view->e((string) count($s['citations'])) ?> sources)</summary><p><?= nl2br($view->e($s['text'])) ?></p><?php if ($s['citations'] !== []): ?><ul><?php foreach ($s['citations'] as $c): ?><li><a href="<?= $view->e($c['url']) ?>" rel="noopener noreferrer nofollow"><?= $view->e($c['title'] ?? $c['url']) ?></a></li><?php endforeach; ?></ul><?php endif; ?></details></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
