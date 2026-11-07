<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/** Who normally completes a setup step: the user, or the installation administrator / workspace owner. */
enum QuestOwner: string
{
    case User = 'user';
    case Administrator = 'administrator';
}
