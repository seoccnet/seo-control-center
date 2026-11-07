<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Analytics\Ga4AiReferralReport;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\AiReferralRepository;

final readonly class PdoAiReferralRepository implements AiReferralRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}

    public function save(int $projectId, DateRange $range, Ga4AiReferralReport $report): void
    {
        $this->db->transaction(function () use ($projectId, $range, $report): void {
            $this->db->execute('DELETE FROM ga4_ai_referrals WHERE project_id = ? AND date BETWEEN ? AND ?', [$projectId, $range->fromString(), $range->toString()]);
            // GA4 may return a source in two spellings that fold into one lower-case key.
            $merged = [];
            foreach ($report->rows as $row) {
                $key = $row['date'] . '|' . $row['source'];
                $merged[$key] = isset($merged[$key])
                    ? ['date' => $row['date'], 'source' => $row['source'], 'sessions' => $merged[$key]['sessions'] + $row['sessions'], 'key_events' => $merged[$key]['key_events'] + $row['key_events'], 'revenue' => $merged[$key]['revenue'] + $row['revenue']]
                    : $row;
            }
            foreach (array_chunk(array_values($merged), 200) as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    array_push($params, $projectId, $row['date'], $row['source'], $row['sessions'], $row['key_events'], $row['revenue']);
                }
                $this->db->execute('INSERT INTO ga4_ai_referrals (project_id, date, source, sessions, key_events, revenue) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)')), $params);
            }
            $this->db->execute(
                'INSERT INTO ga4_ai_referral_imports (project_id, period_from, period_to, currency, limited, fetched_at, error, error_at) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)
                 ON DUPLICATE KEY UPDATE period_from = VALUES(period_from), period_to = VALUES(period_to), currency = VALUES(currency), limited = VALUES(limited), fetched_at = VALUES(fetched_at), error = NULL, error_at = NULL',
                [$projectId, $range->fromString(), $range->toString(), mb_substr($report->currency, 0, 7), (int) $report->limited, $this->clock->now()->format('Y-m-d H:i:s')],
            );
        });
    }

    public function recordFailure(int $projectId, string $error): void
    {
        $this->db->execute(
            'INSERT INTO ga4_ai_referral_imports (project_id, error, error_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE error = VALUES(error), error_at = VALUES(error_at)',
            [$projectId, mb_substr($error, 0, 500), $this->clock->now()->format('Y-m-d H:i:s')],
        );
    }

    public function lastImport(int $projectId): ?array
    {
        $r = $this->db->fetchOne('SELECT period_from, period_to, currency, limited, fetched_at, error, error_at FROM ga4_ai_referral_imports WHERE project_id = ?', [$projectId]);
        if ($r === null) {
            return null;
        }
        $str = static fn(mixed $v): ?string => $v !== null ? (string) $v : null;

        return ['from' => $str($r['period_from']), 'to' => $str($r['period_to']), 'currency' => $str($r['currency']), 'limited' => (bool) $r['limited'],
            'fetched_at' => $str($r['fetched_at']), 'error' => $str($r['error']), 'error_at' => $str($r['error_at'])];
    }

    public function bySource(int $projectId, DateRange $range): array
    {
        return array_map(
            static fn(array $r): array => ['source' => (string) $r['source'], 'sessions' => (int) $r['sessions'], 'key_events' => (float) $r['key_events'], 'revenue' => (float) $r['revenue']],
            $this->db->fetchAll(
                'SELECT source, SUM(sessions) AS sessions, SUM(key_events) AS key_events, SUM(revenue) AS revenue FROM ga4_ai_referrals
                 WHERE project_id = ? AND date BETWEEN ? AND ? GROUP BY source ORDER BY sessions DESC, source',
                [$projectId, $range->fromString(), $range->toString()],
            ),
        );
    }
}
