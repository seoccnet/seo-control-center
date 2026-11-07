<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

use SeoControlCenter\Domain\Shared\ValidationException;

final readonly class AlertThresholds
{
    public function __construct(
        public int $trafficDrop = 30,
        public int $minimumClicks = 100,
        public int $rankingCutoff = 10,
        public int $lostDomainRank = 30,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $values = ['traffic_drop' => 30, 'minimum_clicks' => 100, 'ranking_cutoff' => 10, 'lost_domain_rank' => 30];
        $errors = [];
        foreach (['traffic_drop' => [1, 100], 'minimum_clicks' => [1, 1000000], 'ranking_cutoff' => [1, 100], 'lost_domain_rank' => [0, 100]] as $field => [$min, $max]) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || !ctype_digit($value) || strlen($value) > 7 || (int) $value < $min || (int) $value > $max) {
                $errors[$field] = sprintf('Enter a whole number between %d and %d.', $min, $max);
            } else {
                $values[$field] = (int) $value;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($values['traffic_drop'], $values['minimum_clicks'], $values['ranking_cutoff'], $values['lost_domain_rank']);
    }
}
