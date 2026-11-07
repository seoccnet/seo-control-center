<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/**
 * How a quest step is completed. Action and state steps are checked against
 * saved data; wait steps depend on background work (queue/cron) and say so.
 */
enum StepKind: string
{
    case Action = 'action';
    case State = 'state';
    case Wait = 'wait';
}
