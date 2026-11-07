<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Outcomes recorded before 0.23.0 have no page_url, so they could never be
 * verified by a detected page change (live check of 0.23.0). Fill it from
 * the opportunity's team task page when one is set, otherwise from the page
 * named in the opportunity's evidence, as completions now do. Rows without
 * either stay NULL. Idempotent: only NULL rows are touched.
 */
return new class implements Migration {
    private const array LABELS = ['Page', 'Ranking URL', 'URL', 'Value page'];

    public function up(Connection $db): void
    {
        $rows = $db->fetchAll(
            'SELECT r.id, o.evidence, t.url AS task_url FROM opportunity_outcomes r
             JOIN opportunities o ON o.id = r.opportunity_id AND o.project_id = r.project_id
             LEFT JOIN opportunity_tasks t ON t.opportunity_id = r.opportunity_id AND t.project_id = r.project_id
             WHERE r.page_url IS NULL',
        );
        foreach ($rows as $row) {
            $url = is_string($row['task_url']) && $row['task_url'] !== '' ? $row['task_url'] : self::fromEvidence((string) $row['evidence']);
            if ($url !== null) {
                $db->execute('UPDATE opportunity_outcomes SET page_url = ? WHERE id = ? AND page_url IS NULL', [mb_substr($url, 0, 2048), (int) $row['id']]);
            }
        }
    }

    private static function fromEvidence(string $json): ?string
    {
        $evidence = json_decode($json, true);
        if (!is_array($evidence)) {
            return null;
        }
        foreach ($evidence as $item) {
            if (!is_array($item) || !in_array($item['label'] ?? null, self::LABELS, true) || !is_string($item['value'] ?? null) || $item['value'] === '—') {
                continue;
            }
            $url = $item['label'] === 'Value page' ? explode(' (', $item['value'], 2)[0] : $item['value'];
            if (is_string(parse_url($url, PHP_URL_PATH))) {
                return $url;
            }
        }

        return null;
    }
};
