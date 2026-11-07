<?php
/**
 * The SEO Control Center logo mark (an open "C" around three rising bars),
 * inline so it needs no request. Decorative: the product name is always next to it.
 * The same drawing is public/assets/img/logo.svg (favicon).
 *
 * With a white-label logo (System → Branding) that image is shown instead.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var (Closure(): SeoControlCenter\Core\Branding\Branding)|null $brand
 */
$branding = isset($brand) ? $brand() : null;
$custom = $branding?->logoUrl();
// A renamed installation without its own logo shows its initial, not the SEO Control Center mark.
$initial = $custom === null && $branding?->productName !== null ? mb_strtoupper(mb_substr($branding->productName, 0, 1)) : null;
?>
<?php if ($custom !== null): ?><img class="logo-mark brand-logo" src="<?= $view->e($custom) ?>" alt=""><?php elseif ($initial !== null): ?><span class="logo-mark brand-initial" aria-hidden="true"><?= $view->e($initial) ?></span><?php else: ?><svg class="logo-mark" viewBox="0 0 64 64" aria-hidden="true" focusable="false"><path d="M51.09 12.91A27 27 0 1 0 51.09 51.09" fill="none" stroke="#7094fc" stroke-width="9"/><rect x="15.6" y="31.6" width="9" height="13.2" fill="#7094fc"/><rect x="27.4" y="26" width="9" height="18.8" fill="#7094fc"/><rect x="39.2" y="19" width="9" height="25.8" fill="#7094fc"/></svg><?php endif; ?>
