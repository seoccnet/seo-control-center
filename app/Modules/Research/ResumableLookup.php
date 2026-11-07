<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use Closure;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/** Durable single-request steps; previous published rows stay visible until completion. */
final class ResumableLookup
{
    public function __construct(private readonly ResearchRepository $repository, private ResearchResult $result) {}

    /** Hours after an uncertain request before an explicit new request may start over. */
    public const int UNCERTAIN_REVIEW_HOURS = 24;

    /**
     * Parameters for an explicitly requested lookup. A failed lookup with the same
     * settings resumes, reusing completed requests. If its last request has an uncertain
     * outcome (it may have been billed), the same lookup is refused for 24 hours so usage
     * can be reviewed; after that a new request starts over without the old progress.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function resumeParams(?ResearchResult $previous, array $params, ?\DateTimeImmutable $now = null): array
    {
        if ($previous?->status !== 'failed') {
            return $params;
        }
        $old = $previous->params;
        $batch = $old['_batch'] ?? null;
        unset($old['_batch']);
        unset($old['_request_id']);
        unset($old['scheduled']);
        $comparison = $params;
        unset($comparison['_request_id']);
        unset($comparison['scheduled']);
        foreach (['domains', 'competitors'] as $field) {
            if (is_array($old[$field] ?? null) && is_array($comparison[$field] ?? null)) {
                sort($old[$field]);
                sort($comparison[$field]);
            }
        }
        if ($old == $comparison && is_array($batch)) {
            if (($batch['pending'] ?? null) !== null) {
                $reviewUntil = (new \DateTimeImmutable($previous->requestedAt, new \DateTimeZone('UTC')))->modify(sprintf('+%d hours', self::UNCERTAIN_REVIEW_HOURS));
                if ($now === null || $now < $reviewUntil) {
                    throw new \SeoControlCenter\Core\Http\Exception\HttpException(409, sprintf(
                        'A previous request has an uncertain outcome and may have been billed. Review DataForSEO usage; this lookup can be started again after %s UTC.',
                        $reviewUntil->format('Y-m-d H:i'),
                    ));
                }

                return $params;
            }
            return [...$params, '_batch' => $batch];
        }
        return $params;
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>|null null when a prior uncertain batch requires review
     */
    public static function scheduledParams(?ResearchResult $previous, array $params): ?array
    {
        if ($previous?->status === 'failed' && is_array($previous->params['_batch'] ?? null)
            && ($previous->params['_batch']['pending'] ?? null) !== null) {
            return null;
        }
        return self::resumeParams($previous, $params);
    }

    /** @param Closure(): list<array<mixed>> $fetch
     * @return list<array<mixed>>
     */
    public function step(string $key, Closure $fetch): array
    {
        $batch = $this->result->params['_batch'] ?? ['done' => [], 'pending' => null];
        if (!is_array($batch) || !is_array($batch['done'] ?? null)) {
            throw new IntegrationException('Invalid saved lookup progress. Start a new lookup.', false);
        }
        if (($batch['pending'] ?? null) !== null) {
            throw new IntegrationException('A previous lookup request has an uncertain outcome. Automatic resumption stopped; review usage before starting a new lookup.', false, chargeUncertain: true);
        }
        if (array_key_exists($key, $batch['done'])) {
            $saved = $batch['done'][$key];
            if (!is_array($saved) || !array_is_list($saved) || count(array_filter($saved, is_array(...))) !== count($saved)) {
                throw new IntegrationException('Invalid saved lookup rows. No provider requests were made.', false);
            }
            return $saved;
        }
        $batch['pending'] = $key;
        $this->save($batch);
        try {
            $rows = $fetch();
        } catch (BudgetExceededException $e) {
            $batch['pending'] = null;
            $this->save($batch);
            throw $e;
        } catch (IntegrationException $e) {
            $knownRejection = $e->providerStatusCode !== null && $e->providerStatusCode >= 40000 && $e->providerStatusCode < 50000;
            if (($e->retryable || $e->authorizationLost || $knownRejection) && !$e->chargeUncertain) {
                $batch['pending'] = null;
                $this->save($batch);
            }
            throw $e;
        }
        $batch['done'][$key] = $rows;
        $batch['pending'] = null;
        $this->save($batch);
        return $rows;
    }

    /** @param array<string, mixed> $batch */
    private function save(array $batch): void
    {
        $params = [...$this->result->params, '_batch' => $batch];
        $this->repository->checkpoint($this->result, $params);
        $this->result = new ResearchResult($this->result->projectId, $this->result->kind, $this->result->subject, $this->result->status, $params, $this->result->items, $this->result->error, $this->result->requestedAt, $this->result->fetchedAt);
    }
}
