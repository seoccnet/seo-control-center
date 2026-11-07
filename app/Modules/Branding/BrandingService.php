<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Branding;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Branding\Branding;
use SeoControlCenter\Core\Branding\BrandingRepository;
use SeoControlCenter\Core\Branding\CurrentBranding;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * White label (system.settings.manage): product name, accent colour, report
 * footer and logo. The logo is stored in storage/branding/ under a random
 * name; only PNG, JPEG and WebP up to 512 KB are accepted (no SVG, which can
 * carry scripts).
 */
final readonly class BrandingService
{
    public const int MAX_LOGO_BYTES = 512 * 1024;
    private const array LOGO_TYPES = [IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_WEBP => ['webp', 'image/webp']];

    public function __construct(
        private BrandingRepository $repository,
        private Authorizer $authorizer,
        private AuditLogger $audit,
        private string $directory,
        private ?CurrentBranding $current = null,
    ) {}

    public function current(): Branding
    {
        return $this->repository->get();
    }

    /**
     * @throws ValidationException
     */
    public function save(User $user, string $productName, string $accentColor, string $reportFooter, ClientInfo $client): void
    {
        $this->authorize($user);
        $productName = trim($productName);
        $accentColor = strtolower(trim($accentColor));
        $reportFooter = trim($reportFooter);
        $errors = [];
        if (mb_strlen($productName) > 60 || preg_match('/[\x00-\x1f<>]/u', $productName) === 1) {
            $errors['product_name'] = 'Use up to 60 characters, without < or >.';
        }
        if ($accentColor !== '' && preg_match('/^#[0-9a-f]{6}$/D', $accentColor) !== 1) {
            $errors['accent_color'] = 'Enter a colour like #2f5fd0.';
        }
        if (mb_strlen($reportFooter) > 300) {
            $errors['report_footer'] = 'Use up to 300 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->repository->save($productName !== '' ? $productName : null, $accentColor !== '' ? $accentColor : null, $reportFooter !== '' ? $reportFooter : null, $user->id);
        $this->current?->forget();
        $this->audit->record(new AuditEvent('system.branding_saved', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, metadata: [
            'product_name' => $productName !== '' ? $productName : null,
            'accent_color' => $accentColor !== '' ? $accentColor : null,
        ]), $client);
    }

    /**
     * @throws ValidationException
     */
    public function uploadLogo(User $user, string $uploadedFile, int $size, ClientInfo $client): void
    {
        $this->authorize($user);
        $info = $size > 0 && $size <= self::MAX_LOGO_BYTES ? @getimagesize($uploadedFile) : false;
        $type = is_array($info) ? self::LOGO_TYPES[$info[2]] ?? null : null;
        if ($type === null || !is_array($info) || $info[0] < 16 || $info[1] < 16 || $info[0] > 2000 || $info[1] > 2000) {
            throw new ValidationException(['logo' => 'Upload a PNG, JPEG or WebP image of up to 512 KB (between 16 and 2,000 pixels per side).']);
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            throw new ValidationException(['logo' => 'The logo cannot be stored (permissions of storage/?).']);
        }
        $version = bin2hex(random_bytes(8));
        $file = 'logo-' . $version . '.' . $type[0];
        if (!@copy($uploadedFile, $this->directory . '/' . $file)) {
            throw new ValidationException(['logo' => 'The logo cannot be stored (permissions of storage/?).']);
        }
        $previous = $this->repository->get()->logoFile;
        $this->repository->setLogo($file, $version, $user->id);
        $this->current?->forget();
        $this->deleteFile($previous);
        $this->audit->record(new AuditEvent('system.branding_logo_uploaded', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, metadata: ['type' => $type[1], 'bytes' => $size]), $client);
    }

    public function removeLogo(User $user, ClientInfo $client): void
    {
        $this->authorize($user);
        $previous = $this->repository->get()->logoFile;
        $this->repository->setLogo(null, null, $user->id);
        $this->current?->forget();
        $this->deleteFile($previous);
        $this->audit->record(new AuditEvent('system.branding_logo_removed', ActorType::User, actorUserId: $user->id, actorLabel: $user->email), $client);
    }

    /**
     * The stored logo file and its content type, or null.
     *
     * @return array{path: string, type: string}|null
     */
    public function logo(): ?array
    {
        $file = $this->repository->get()->logoFile;
        if ($file === null || preg_match('/^logo-[0-9a-f]{16}\.(png|jpg|webp)$/D', $file, $m) !== 1 || !is_file($this->directory . '/' . $file)) {
            return null;
        }

        return ['path' => $this->directory . '/' . $file, 'type' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$m[1]]];
    }

    /**
     * CSS that overrides the accent colour in light and dark mode.
     */
    public static function stylesheet(Branding $branding): string
    {
        $accent = $branding->accentColor;
        if ($accent === null) {
            return "/* default colours */\n";
        }
        $strong = self::shade($accent, -0.18);

        return ":root, :root[data-theme] {\n"
            . "    --accent: {$accent};\n"
            . "    --accent-strong: {$strong};\n"
            . "    --accent-soft: color-mix(in srgb, {$accent} 16%, var(--surface));\n"
            . '    --on-accent: ' . (self::luminance($accent) > 0.5 ? '#141a22' : '#ffffff') . ";\n"
            . "}\n";
    }

    private static function shade(string $hex, float $amount): string
    {
        $out = '#';
        foreach (str_split(substr($hex, 1), 2) as $channel) {
            $value = hexdec($channel);
            $value = $amount < 0 ? $value * (1 + $amount) : $value + (255 - $value) * $amount;
            $out .= sprintf('%02x', max(0, min(255, (int) round($value))));
        }

        return $out;
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static fn(string $c): float => hexdec($c) / 255, str_split(substr($hex, 1), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function deleteFile(?string $file): void
    {
        if ($file !== null && preg_match('/^logo-[0-9a-f]{16}\.(png|jpg|webp)$/D', $file) === 1) {
            @unlink($this->directory . '/' . $file);
        }
    }

    private function authorize(User $user): void
    {
        $this->authorizer->authorize($user->subject(), Permission::SystemSettingsManage);
    }
}
