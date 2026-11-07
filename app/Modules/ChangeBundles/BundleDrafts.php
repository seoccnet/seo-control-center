<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Domain\ChangeBundles\BundleRules;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Opportunities\OpportunityService;
use SeoControlCenter\Modules\PageMonitoring\PageMonitoringService;

/**
 * Starts a change bundle from an opportunity (0.41.0): the finding becomes
 * the problem and evidence, its action plan the expected effect and the
 * revert rule, and the plan's usual fields become empty edit rows for a
 * person to fill in. The source is kept so a measurement plan can be drafted
 * when the deployment is recorded. Nothing is saved until the person saves.
 */
final readonly class BundleDrafts
{
    public function __construct(private ?OpportunityService $opportunities) {}

    /**
     * @return array{value: array{title: string, url: string, problem: string, evidence: string, edits: list<array{field: string, before: string, after: string}>, expected: string, validation: string, version: int}, source: array{opportunity_id: string, playbook: string, metric: string, days: int, query: ?string, objective: string}}|null
     */
    public function fromOpportunity(Project $project, string $id): ?array
    {
        if ($this->opportunities === null) {
            return null;
        }
        try {
            $detail = $this->opportunities->detail($project, $id);
        } catch (HttpException) {
            return null;
        }
        $row = $detail['opportunity'];
        $plan = $detail['action_plan'];
        $value = static function (array $labels) use ($row): ?string {
            foreach ($row->evidence as $item) {
                if (in_array($item['label'], $labels, true) && trim($item['value']) !== '' && $item['value'] !== '—') {
                    return trim(explode(' (', $item['value'], 2)[0]);
                }
            }
            return null;
        };
        $url = $value(['Page', 'Ranking URL', 'URL', 'Value page']) ?? (str_starts_with($row->subject, 'page:') ? substr($row->subject, 5) : '');
        $url = PageMonitoringService::validUrl($project, $url) ? $url : '';
        [$fields, $metric] = BundleRules::fromPlaybook($plan->key);
        $measure = $plan->items('What to measure');
        $revert = $plan->items('When to revert');

        return [
            'value' => [
                'title' => mb_substr($row->title, 0, 200),
                'url' => $url,
                'problem' => mb_substr($row->title . '. ' . $row->explanation, 0, 2000),
                'evidence' => mb_substr(implode("\n", array_map(static fn(array $e): string => $e['label'] . ': ' . $e['value'], $row->evidence)), 0, 4000),
                'edits' => array_map(static fn(string $field): array => ['field' => $field, 'before' => '', 'after' => ''], $fields),
                'expected' => mb_substr($plan->goal . ($measure !== [] ? ' ' . $measure[0] : ''), 0, 2000),
                'validation' => mb_substr($revert !== [] ? implode(' ', $revert) : implode(' ', $measure), 0, 2000),
                'version' => 0,
            ],
            'source' => ['opportunity_id' => $row->publicId, 'playbook' => $plan->key, 'metric' => $metric, 'days' => 28, 'query' => $value(['Query', 'Keyword']), 'objective' => mb_substr($plan->goal, 0, 1000)],
        ];
    }
}
