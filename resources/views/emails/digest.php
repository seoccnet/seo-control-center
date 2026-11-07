<?php
/**
 * HTML version of the weekly digest: tables and inline styles only, no
 * images, scripts or remote resources. The plain-text part carries the same
 * figures.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var list<array{name: string, url: string, reportUrl: string, clicks: ?int, clicksBefore: ?int, top10: ?int, top10Before: ?int, winners: list<string>, losers: list<string>, openOpportunities: int, opportunities: list<string>, score: ?int, scoreBefore: ?int, alerts: list<string>}> $weeks
 * @var ?string $summary
 * @var string $workspace
 * @var string $settingsUrl
 * @var string $productName
 */
$font = 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;';
$delta = static function (?int $now, ?int $before, bool $percent) use ($view): string {
    if ($now === null || $before === null || ($percent && $before === 0)) {
        return '';
    }
    $diff = $percent ? (int) round(($now - $before) / $before * 100) : $now - $before;
    if ($diff === 0) {
        return '<span style="color:#5f6b7a;">no change</span>';
    }

    return '<span style="color:' . ($diff > 0 ? '#1b6b43' : '#a64614') . ';">' . ($diff > 0 ? '▲' : '▼') . ' ' . $view->e(sprintf($percent ? '%+d %%' : '%+d', $diff)) . '</span>';
};
$tile = static fn(string $label, string $value, string $change) => '<td style="padding:10px 12px;border:1px solid #dde2e8;border-radius:8px;vertical-align:top;width:33%;">'
    . '<div style="font-size:12px;color:#5f6b7a;">' . $view->e($label) . '</div>'
    . '<div style="font-size:20px;font-weight:600;color:#141a22;margin:2px 0;">' . $view->e($value) . '</div>'
    . ($change !== '' ? '<div style="font-size:12px;">' . $change . '</div>' : '')
    . '</td>';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $view->e('Your week in SEO: ' . $workspace) ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f6f8;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;">
    <tr><td align="center" style="padding:24px 12px;">
        <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #dde2e8;border-radius:10px;<?= $font ?>color:#141a22;">
            <tr><td style="padding:24px 24px 8px;">
                <div style="font-size:12px;color:#5f6b7a;"><span style="color:#2f5fd0;font-weight:600;"><?= $view->e($productName) ?></span> · Weekly digest</div>
                <h1 style="font-size:22px;margin:6px 0 4px;">Your week in SEO: <?= $view->e($workspace) ?></h1>
            </td></tr>
            <?php if ($summary !== null): ?>
                <tr><td style="padding:8px 24px;">
                    <div style="background:#f0f4fd;border-left:3px solid #2f5fd0;border-radius:6px;padding:12px 14px;font-size:14px;line-height:1.55;">
                        <div style="font-size:11px;font-weight:600;color:#2f5fd0;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">AI summary</div>
                        <?= nl2br($view->e($summary)) ?>
                    </div>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($weeks as $w): ?>
                <tr><td style="padding:16px 24px 4px;border-top:1px solid #eef0f3;">
                    <div style="font-size:16px;font-weight:600;margin-bottom:8px;"><a href="<?= $view->e($w['url']) ?>" style="color:#141a22;text-decoration:none;"><?= $view->e($w['name']) ?></a></div>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="6" style="border-collapse:separate;">
                        <tr>
                            <?= $tile('Clicks', $w['clicks'] !== null ? number_format($w['clicks']) : '—', $delta($w['clicks'], $w['clicksBefore'], true)) ?>
                            <?= $tile('Top 10 keywords', $w['top10'] !== null ? (string) $w['top10'] : '—', $delta($w['top10'], $w['top10Before'], false)) ?>
                            <?= $tile('Site health', $w['score'] !== null ? (string) $w['score'] : '—', $delta($w['score'], $w['scoreBefore'], false)) ?>
                        </tr>
                    </table>
                    <div style="font-size:13px;line-height:1.6;margin:6px 0;">
                        <?php if ($w['winners'] !== []): ?><div><span style="color:#1b6b43;font-weight:600;">Up:</span> <?= $view->e(implode(', ', $w['winners'])) ?></div><?php endif; ?>
                        <?php if ($w['losers'] !== []): ?><div><span style="color:#a64614;font-weight:600;">Down:</span> <?= $view->e(implode(', ', $w['losers'])) ?></div><?php endif; ?>
                        <?php foreach ($w['alerts'] as $title): ?><div><span style="color:#a64614;font-weight:600;">Alert:</span> <?= $view->e($title) ?></div><?php endforeach; ?>
                        <div style="margin-top:4px;"><strong><?= $w['openOpportunities'] ?></strong> open opportunities<?= $w['opportunities'] !== [] ? ', the top ones:' : '' ?></div>
                        <?php if ($w['opportunities'] !== []): ?>
                            <ul style="margin:2px 0 0;padding-left:18px;">
                                <?php foreach ($w['opportunities'] as $title): ?><li><?= $view->e($title) ?></li><?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:13px;margin:6px 0 10px;"><a href="<?= $view->e($w['reportUrl']) ?>" style="color:#2f5fd0;">Weekly report →</a></div>
                </td></tr>
            <?php endforeach; ?>
            <tr><td style="padding:12px 24px;border-top:1px solid #dde2e8;font-size:12px;color:#5f6b7a;">
                You get this digest every Monday. <a href="<?= $view->e($settingsUrl) ?>" style="color:#5f6b7a;">Choose what you receive</a>.
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
