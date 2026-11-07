<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/** Routes each signal to the module that owns it; unknown signals are never satisfied. */
final readonly class CompositeQuestEvidence implements QuestEvidenceSource
{
    /** @var array<string, QuestEvidenceSource> */
    private array $routes;

    /** @param list<QuestEvidenceSource> $sources */
    public function __construct(array $sources)
    {
        $routes = [];
        foreach ($sources as $source) {
            foreach ($source->signals() as $signal) {
                if (isset($routes[$signal])) {
                    throw new \LogicException(sprintf('Quest signal "%s" has two sources.', $signal));
                }
                $routes[$signal] = $source;
            }
        }
        $this->routes = $routes;
    }

    public function signals(): array
    {
        return array_map(strval(...), array_keys($this->routes));
    }

    public function has(string $signal, EvidenceScope $scope): bool
    {
        return isset($this->routes[$signal]) && $this->routes[$signal]->has($signal, $scope);
    }
}
