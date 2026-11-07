<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * One explainable rule. Rules are pure: same signals, same opportunities.
 */
interface OpportunityRule
{
    /** @return list<string> Sources needed to replace or resolve this rule's findings; null describes all branches. */
    public function requires(?Opportunity $opportunity = null): array;

    /**
     * @return list<Opportunity>
     */
    public function evaluate(ProjectSignals $signals): array;
}
