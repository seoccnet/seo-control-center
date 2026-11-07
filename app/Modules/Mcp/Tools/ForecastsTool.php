<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Forecasting\ForecastEvent;
use SeoControlCenter\Modules\Forecasting\ForecastSeries;
use SeoControlCenter\Modules\Forecasting\ForecastService;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

/**
 * Forecasts of stored daily series (the Forecasts page): current drafts with
 * their method and calibration, saved forecasts scored against actuals, and
 * the event calendar. Computed from stored data; nothing is saved.
 */
final readonly class ForecastsTool implements McpTool
{
    public function __construct(private ForecastService $forecasts) {}

    public function name(): string
    {
        return 'forecasts';
    }

    public function title(): string
    {
        return 'Forecasts';
    }

    public function scope(): McpScope
    {
        return McpScope::Search;
    }

    public function description(): string
    {
        return 'Statistical forecasts of the project\'s stored daily series (Search Console and other imported metrics) for 7, 14 or 28 days: per series the selected method, the predicted value on the target day, an interval when enough saved forecasts have been scored, and why a series cannot be forecast. Also saved forecasts scored against actuals and the event calendar used by the models. A projection from history, not a promise; it ignores changes the data has not seen. Computes from stored data and saves nothing.';
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'horizon' => ['type' => 'integer', 'enum' => [7, 14, 28], 'default' => 14],
            'include_points' => ['type' => 'boolean', 'default' => false, 'description' => 'Include the daily predicted values up to the target day.'],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $horizon = $arguments['horizon'] ?? 14;
        if (!in_array($horizon, [7, 14, 28], true)) {
            throw new \SeoControlCenter\Modules\Mcp\Server\ToolError('horizon must be 7, 14 or 28.');
        }
        $points = ($arguments['include_points'] ?? false) === true;
        $overview = $this->forecasts->overview($project, $horizon);

        return [
            ...Present::project($project),
            'issued' => $overview['issued'],
            'horizon_days' => $horizon,
            'forecasts' => array_map(static function (array $draft) use ($points): array {
                /** @var ForecastSeries $series */
                $series = $draft['series'];
                $forecast = $draft['forecast'];

                return [
                    'series' => $series->key,
                    'label' => $series->label,
                    'method' => $forecast['method'] !== '' ? $forecast['method'] : null,
                    'target_date' => $draft['target'],
                    'prediction' => $draft['prediction'] !== null ? round((float) $draft['prediction'], 2) : null,
                    'interval' => $draft['lower'] !== null && $draft['upper'] !== null ? ['lower' => round((float) $draft['lower'], 2), 'upper' => round((float) $draft['upper'], 2)] : null,
                    'calibration' => $draft['calibration'],
                    'training' => ['observations' => $forecast['observations'], 'from' => $forecast['from'], 'to' => $forecast['to'], 'test_windows' => $forecast['folds'], 'mean_absolute_error' => $forecast['mae']],
                    'not_forecast_reason' => $forecast['reason'],
                    'points' => $points ? $forecast['points'] : null,
                ];
            }, $overview['drafts']),
            'saved_forecasts' => ['total' => $overview['recordCount'], 'latest' => array_map(static fn(array $h): array => array_intersect_key($h, array_flip(['key', 'label', 'method', 'issued', 'horizon', 'target', 'prediction', 'lower', 'upper', 'actual', 'error', 'covered'])), $overview['history'])],
            'tracking' => $overview['tracking'],
            'event_calendar' => array_map(static fn(ForecastEvent $e): array => ['name' => $e->name, 'date' => $e->date, 'known_on' => $e->knownOn, 'excluded_on' => $e->excludedOn], $overview['calendar']),
            'notice' => 'Forecasts project stored history forward and cannot know about changes the data has not seen. Intervals appear only after at least 20 saved forecasts were scored. Null is unavailable, not zero.',
        ];
    }
}
