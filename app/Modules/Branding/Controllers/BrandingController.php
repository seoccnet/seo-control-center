<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Branding\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Branding\BrandingService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * System → Branding (white label), plus the public logo and colour
 * stylesheet that every page, the sign-in page included, links to.
 */
final readonly class BrandingController
{
    private const string PATH = '/system/branding';

    public function __construct(
        private BrandingService $service,
        private Authorizer $authorizer,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private string $tmpDirectory,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizer->authorize(WebContext::user($request)->subject(), Permission::SystemSettingsManage);

        return $this->page($request, [], [], 200);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        $values = [];
        foreach (['product_name', 'accent_color', 'report_footer'] as $field) {
            $values[$field] = is_string($body[$field] ?? null) ? $body[$field] : '';
        }
        try {
            $this->service->save(WebContext::user($request), $values['product_name'], $values['accent_color'], $values['report_footer'], ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), $values, 422);
        }
        WebContext::session($request)->flash('status', 'Branding saved.');

        return $this->responses->redirect(self::PATH);
    }

    public function uploadLogo(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $this->authorizer->authorize($user->subject(), Permission::SystemSettingsManage);
        $file = $request->getUploadedFiles()['logo'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->page($request, ['logo' => 'Choose a PNG, JPEG or WebP image of up to 512 KB.'], [], 422);
        }
        if (!is_dir($this->tmpDirectory)) {
            @mkdir($this->tmpDirectory, 0o750, true);
        }
        $temporary = $this->tmpDirectory . '/logo-upload-' . bin2hex(random_bytes(6));
        $file->moveTo($temporary);
        try {
            $this->service->uploadLogo($user, $temporary, (int) $file->getSize(), ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), [], 422);
        } finally {
            @unlink($temporary);
        }
        WebContext::session($request)->flash('status', 'Logo uploaded.');

        return $this->responses->redirect(self::PATH);
    }

    public function removeLogo(ServerRequestInterface $request): ResponseInterface
    {
        $this->service->removeLogo(WebContext::user($request), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Logo removed; the default logo is shown again.');

        return $this->responses->redirect(self::PATH);
    }

    /**
     * Public: the uploaded logo (versioned URL, cached for a year).
     */
    public function logo(ServerRequestInterface $request): ResponseInterface
    {
        $logo = $this->service->logo() ?? throw new NotFoundHttpException();

        return $this->responses->text((string) file_get_contents($logo['path']))
            ->withHeader('Content-Type', $logo['type'])
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'")
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }

    /**
     * Public: the accent colour as CSS custom properties (versioned URL).
     */
    public function stylesheet(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->text(BrandingService::stylesheet($this->service->current()))
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function page(ServerRequestInterface $request, array $errors, array $old, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('system/branding', [
            'branding' => $this->service->current(),
            'errors' => $errors,
            'old' => $old,
            'maxLogoKb' => intdiv(BrandingService::MAX_LOGO_BYTES, 1024),
        ], $request), $status);
    }
}
