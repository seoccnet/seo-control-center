<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Analytics;

/**
 * A GA4 event the project counts as business success (ADR-042): a purchase,
 * a qualified lead, a subscription or another goal, by its exact event name.
 */
final readonly class BusinessEvent
{
    public const string PURCHASE = 'purchase';
    public const string LEAD = 'lead';
    public const string SUBSCRIPTION = 'subscription';
    public const string OTHER = 'other';

    /** Kind => [label, the GA4 recommended event name]. */
    public const array KINDS = [
        self::PURCHASE => ['Purchase', 'purchase'],
        self::LEAD => ['Qualified lead', 'generate_lead'],
        self::SUBSCRIPTION => ['Subscription or sign-up', 'sign_up'],
        self::OTHER => ['Other goal', ''],
    ];

    public const int MAX_PER_PROJECT = 10;

    public function __construct(
        public string $publicId,
        public int $projectId,
        public string $kind,
        public string $eventName,
        public string $label,
        public string $createdAt,
    ) {}

    /** GA4 event names: a letter first, then letters, digits or underscores, at most 40 characters. */
    public static function validName(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/D', $name) === 1;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind][0] ?? 'Other goal';
    }
}
