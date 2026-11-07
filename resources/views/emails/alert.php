<?php
/**
 * HTML version of an alert e-mail: tables and inline styles only, no images,
 * scripts or remote resources. The plain-text part carries the same lines.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Modules\Alerts\Alert $alert
 * @var string $url
 * @var string $settingsUrl
 * @var string $productName
 */
$font = 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $view->e($alert->title) ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f6f8;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;">
    <tr><td align="center" style="padding:24px 12px;">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #dde2e8;border-radius:10px;<?= $font ?>color:#141a22;">
            <tr><td style="padding:24px 24px 8px;">
                <div style="font-size:12px;color:#5f6b7a;"><span style="color:#a64614;font-weight:600;">Alert</span> · <?= $view->e($productName) ?> · <?= $view->e($alert->type->label()) ?></div>
                <h1 style="font-size:20px;margin:6px 0 4px;"><?= $view->e($alert->title) ?></h1>
            </td></tr>
            <?php if ($alert->lines !== []): ?>
                <tr><td style="padding:4px 24px 8px;">
                    <ul style="margin:0;padding-left:18px;font-size:14px;line-height:1.6;">
                        <?php foreach ($alert->lines as $line): ?>
                            <li><?= $view->e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </td></tr>
            <?php endif; ?>
            <tr><td style="padding:12px 24px 24px;">
                <a href="<?= $view->e($url) ?>" style="display:inline-block;background:#2f5fd0;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:10px 16px;border-radius:6px;">Open in <?= $view->e($productName) ?></a>
            </td></tr>
            <tr><td style="padding:12px 24px;border-top:1px solid #dde2e8;font-size:12px;color:#5f6b7a;">
                You get this e-mail because alerts are on for your account. <a href="<?= $view->e($settingsUrl) ?>" style="color:#5f6b7a;">Choose what you receive</a>.
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
