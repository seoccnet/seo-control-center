<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Audit;

enum ActorType: string
{
    case User = 'user';
    case System = 'system';
    case Cli = 'cli';
    case Mcp = 'mcp';
    case Api = 'api';
}
