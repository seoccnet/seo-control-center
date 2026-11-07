<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Branding\Persistence;

use SeoControlCenter\Core\Branding\Branding;
use SeoControlCenter\Core\Branding\BrandingRepository;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;

final readonly class PdoBrandingRepository implements BrandingRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function get(): Branding
    {
        $row = $this->db->fetchOne('SELECT * FROM branding_settings WHERE id = 1');
        if ($row === null) {
            return Branding::none();
        }

        return new Branding(
            $row['product_name'] !== null ? (string) $row['product_name'] : null,
            $row['accent_color'] !== null ? (string) $row['accent_color'] : null,
            $row['report_footer'] !== null ? (string) $row['report_footer'] : null,
            $row['logo_file'] !== null ? (string) $row['logo_file'] : null,
            $row['logo_version'] !== null ? (string) $row['logo_version'] : null,
        );
    }

    public function save(?string $productName, ?string $accentColor, ?string $reportFooter, int $userId): void
    {
        $this->db->execute(
            'INSERT INTO branding_settings (id, product_name, accent_color, report_footer, updated_by, updated_at) VALUES (1, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), accent_color = VALUES(accent_color), report_footer = VALUES(report_footer),
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$productName, $accentColor, $reportFooter, $userId, $this->now()],
        );
    }

    public function setLogo(?string $file, ?string $version, int $userId): void
    {
        $this->db->execute(
            'INSERT INTO branding_settings (id, logo_file, logo_version, updated_by, updated_at) VALUES (1, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE logo_file = VALUES(logo_file), logo_version = VALUES(logo_version), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$file, $version, $userId, $this->now()],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
