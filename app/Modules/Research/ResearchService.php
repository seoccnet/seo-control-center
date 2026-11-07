<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Research\AiMention;
use SeoControlCenter\Domain\Research\AiMentionsProvider;
use SeoControlCenter\Domain\Research\ResearchDomain;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\Research\ResearchProvider;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Keywords\KeywordService;

/**
 * Keyword research for a project from the provider's keyword database:
 * the project's ranking keywords, the keyword gap to each competitor,
 * keyword ideas, competitor discovery and a domain overview. Lookups cost
 * money (analysis.execute, feature keyword_metrics), run as jobs
 * and are stored, so reading them again is free.
 */
final readonly class ResearchService
{
    public const array LIMITS = ['ranked' => 1000, 'gap' => 500, 'ideas' => 300, 'competitors' => 20, 'suggestions' => 100];
    /** Lookups served by DataForSEO Labs, which covers some markets only. */
    public const array LABS_KINDS = ['ranked', 'gap', 'ideas', 'suggestions', 'competitors', 'overview'];
    public const int MAX_SEEDS = 20;
    /** Seeds per suggestions lookup; each seed is one request. */
    public const int MAX_SUGGESTION_SEEDS = 10;
    public const int MAX_MODIFIERS = 30;
    /** Days between weekly AI mention checks. */
    private const int WEEK_DAYS = 7;
    /** Questions per AI platform in an llm_prompts lookup. */
    public const int ANSWERS_PER_PLATFORM = 25;

    public function __construct(
        private ResearchRepository $results,
        private ResearchScheduler $scheduler,
        private ResearchProvider $provider,
        private AiMentionsProvider $mentions,
        private CompetitorRepository $competitors,
        private KeywordRepository $keywords,
        private KeywordService $keywordService,
        private ProjectService $projects,
        private FeatureManager $features,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private LoggerInterface $logger,
        private AiMentionHistory $history,
        private ProjectRepository $projectRepository,
        private Clock $clock,
        private ?\SeoControlCenter\Domain\Research\AiAnswerSampler $sampler = null,
        private ?BrandMentionRepository $brands = null,
        private ?\SeoControlCenter\Domain\Research\DomainStrengthProvider $strength = null,
        private ?\SeoControlCenter\Domain\Research\SeedSuggester $seedSuggester = null,
        private ?\SeoControlCenter\Domain\Opportunities\BusinessContextRepository $business = null,
        private ?\SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository $serps = null,
        private ?\SeoControlCenter\Domain\Rankings\RankingRepository $rankings = null,
    ) {}

    /** Prompts per AI answer check; each is sent to every sampled platform. */
    public const int MAX_SAMPLE_PROMPTS = 10;

    /**
     * @return array{
     *     locale: KeywordLocale,
     *     ranked: ?ResearchResult,
     *     ideas: ?ResearchResult,
     *     suggestions: ?ResearchResult,
     *     seedIdeas: ?ResearchResult,
     *     business: ?\SeoControlCenter\Domain\Opportunities\BusinessContext,
     *     canSuggestSeeds: bool,
     *     competitorsResult: ?ResearchResult,
     *     overview: ?ResearchResult,
     *     gaps: array<string, ResearchResult>,
     *     competitors: list<Competitor>,
     *     tracked: array<string, true>,
     *     canRun: bool,
     *     canTrack: bool,
     *     available: bool,
     *     featureOn: bool,
     *     costs: array<string, float>
     * }
     */
    public function page(User $user, Project $project): array
    {
        $gaps = [];
        foreach ($this->results->forKind($project->id, 'gap') as $result) {
            $gaps[$result->subject] = $result;
        }
        $competitors = $this->competitors->forProject($project->id);
        $tracked = [];
        foreach ($this->keywords->texts($project->id) as $text) {
            $tracked[mb_strtolower($text)] = true;
        }

        return [
            'locale' => $this->keywords->lastLocale($project->id) ?? KeywordLocale::default(),
            'ranked' => $this->results->find($project->id, 'ranked'),
            'ideas' => $this->results->find($project->id, 'ideas'),
            'suggestions' => $this->results->find($project->id, 'suggestions'),
            'seedIdeas' => $this->results->find($project->id, 'seed_ideas'),
            'business' => $this->business?->find($project->id),
            'relevance' => new \SeoControlCenter\Domain\Opportunities\Relevance($this->business?->find($project->id), $this->business?->ratings($project->id) ?? []),
            'canSuggestSeeds' => $this->seedSuggester?->available($project->workspaceId) === true,
            'competitorsResult' => $this->results->find($project->id, 'competitors'),
            'overview' => $this->results->find($project->id, 'overview'),
            'gaps' => $gaps,
            'competitors' => $competitors,
            'tracked' => $tracked,
            'canRun' => $this->projects->can($user, Permission::AnalysisExecute, $project),
            'canTrack' => $this->projects->can($user, Permission::ProjectsEdit, $project),
            'available' => $this->provider->isAvailable($project->workspaceId),
            'featureOn' => $this->features->enabled(Feature::KeywordMetrics),
            'costs' => [
                'ranked' => $this->provider->estimatedCost(self::LIMITS['ranked']),
                'gap' => $this->provider->estimatedCost(self::LIMITS['gap']),
                'ideas' => $this->provider->estimatedCost(self::LIMITS['ideas']),
                'suggestions' => $this->provider->estimatedCost(self::LIMITS['suggestions']),
                'competitors' => $this->provider->estimatedCost(self::LIMITS['competitors']),
                'overview' => $this->provider->estimatedCost(count($competitors) + 1, count($competitors) + 1),
            ],
        ];
    }

    /**
     * Queues a lookup. $competitor is a competitor's public ID (gap only),
     * $seeds the seed keywords, one per line (ideas only).
     *
     * Before anything is spent, the business profile is applied (ADR-037):
     * seeds with an excluded term or a job search are dropped, and a market
     * outside the profile's markets is refused, unless $confirmed.
     *
     * @return list<array{seed: string, reason: string}> the seeds that were dropped
     * @throws ValidationException
     */
    public function request(User $user, Project $project, string $kind, ?string $competitor, string $seeds, mixed $location, mixed $language, ClientInfo $client, string $brand = '', string $modifiers = '', bool $confirmed = false, bool $runAgain = false): array
    {
        if (!in_array($kind, ResearchResult::KINDS, true) || $kind === 'seed_ideas') {
            throw new HttpException(400, 'Unknown lookup.');
        }
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot start paid analyses for this project.');
        }
        $this->features->require(Feature::KeywordMetrics);
        if (!$this->provider->isAvailable($project->workspaceId)) {
            throw new HttpException(409, 'Connect DataForSEO under Integrations first.');
        }
        // Domain strength has no market; the strength check is started without one.
        $locale = $kind === 'serp_strength' && $location === null ? ($this->keywords->lastLocale($project->id) ?? KeywordLocale::default()) : KeywordLocale::fromInput($location, $language);
        // DataForSEO Labs has data for some country and language pairs only: refuse before anything is queued or paid (0.44.3).
        $labsProblem = in_array($kind, self::LABS_KINDS, true) ? \SeoControlCenter\Domain\Keywords\Markets::labsProblem($locale->locationCode, $locale->languageCode) : null;
        if ($labsProblem !== null) {
            throw new ValidationException(['market' => $labsProblem]);
        }
        $domain = self::bareDomain($project->primaryDomain);
        $params = ['location' => $locale->locationCode, 'language' => $locale->languageCode, 'domain' => $domain];
        $subject = '';
        $profile = $this->business?->find($project->id);
        $relevance = new \SeoControlCenter\Domain\Opportunities\Relevance($profile, $this->business?->ratings($project->id) ?? []);
        if ($profile !== null && $kind !== 'serp_strength' && !$confirmed && !$profile->servesMarket($locale)) {
            throw new ValidationException(['market' => sprintf('%s is not one of the markets in your business profile (%s). Tick “Run anyway” to spend on it.', $locale->label(), implode(', ', array_map(static fn(string $key): string => KeywordLocale::fromKey($key)?->label() ?? $key, $profile->markets)))]);
        }
        $dropped = [];
        $keep = static function (array $list) use ($relevance, $confirmed, &$dropped): array {
            if ($confirmed) {
                return $list;
            }
            $kept = [];
            foreach ($list as $seed) {
                $reason = $relevance->clash((string) $seed);
                if ($reason !== null && $relevance->of((string) $seed)['source'] !== 'team') {
                    $dropped[] = ['seed' => (string) $seed, 'reason' => $reason];
                } else {
                    $kept[] = $seed;
                }
            }

            return $kept;
        };

        if ($kind === 'gap') {
            $target = null;
            foreach ($this->competitors->forProject($project->id) as $c) {
                if ($c->publicId === $competitor) {
                    $target = $c;
                }
            }
            if ($target === null) {
                throw new NotFoundHttpException();
            }
            $subject = $target->publicId;
            $params['competitor'] = $target->domain;
        } elseif ($kind === 'ideas') {
            $list = array_values(array_unique(array_filter(array_map(static fn(string $s): string => mb_strtolower(trim($s)), preg_split('/[\r\n,]+/', $seeds) ?: []), static fn(string $s): bool => $s !== '' && mb_strlen($s) <= 100)));
            if ($list === [] || count($list) > self::MAX_SEEDS) {
                throw new ValidationException(['seeds' => sprintf('Enter between 1 and %d seed keywords, one per line.', self::MAX_SEEDS)]);
            }
            $list = $keep($list);
            if ($list === []) {
                throw new ValidationException(['seeds' => self::allDropped($dropped)]);
            }
            $params['seeds'] = $list;
        } elseif ($kind === 'suggestions') {
            $list = self::lines($seeds, 100);
            if ($list === [] || count($list) > self::MAX_SUGGESTION_SEEDS) {
                throw new ValidationException(['seeds' => sprintf('Enter between 1 and %d seed keywords, one per line.', self::MAX_SUGGESTION_SEEDS)]);
            }
            $mods = self::lines($modifiers, 40);
            if (count($mods) > self::MAX_MODIFIERS) {
                throw new ValidationException(['modifiers' => sprintf('Enter at most %d modifiers.', self::MAX_MODIFIERS)]);
            }
            $list = $keep($list);
            if ($list === []) {
                throw new ValidationException(['seeds' => self::allDropped($dropped)]);
            }
            $params['seeds'] = $list;
            $params['modifiers'] = $mods;
        } elseif ($kind === 'serp_strength') {
            $this->features->require(Feature::Backlinks);
            if ($this->strength === null || $this->serps === null || $this->rankings === null || !$this->strength->isAvailable($project->workspaceId)) {
                throw new HttpException(409, 'Connect DataForSEO under Integrations first.');
            }
            $domains = $this->serps->domains($project->id, $this->rankings->settings($project->id)->device, \SeoControlCenter\Domain\Research\DomainStrengthProvider::MAX_DOMAINS);
            if ($domains === []) {
                throw new ValidationException(['domains' => 'No stored results pages yet. Switch on rank tracking and wait for the first check.']);
            }
            $params['domains'] = $domains;
        } elseif ($kind === 'overview' || $kind === 'llm') {
            $params['domains'] = [$domain, ...array_map(static fn(Competitor $c): string => $c->domain, $this->competitors->forProject($project->id))];
        } elseif ($kind === 'llm_answers') {
            if ($this->sampler === null || !$this->sampler->isAvailable($project->workspaceId)) {
                throw new HttpException(409, 'Connect DataForSEO under Integrations first.');
            }
            $prompts = [];
            foreach (preg_split('/\R/', $seeds) ?: [] as $line) {
                $line = trim((string) preg_replace('/\s+/u', ' ', $line));
                if ($line !== '') {
                    $prompts[mb_strtolower($line)] ??= $line; // the first spelling wins
                }
            }
            $prompts = array_values($prompts);
            $errors = [];
            if ($prompts === [] || count($prompts) > self::MAX_SAMPLE_PROMPTS) {
                $errors['prompts'] = sprintf('Enter between 1 and %d prompts, one per line.', self::MAX_SAMPLE_PROMPTS);
            } elseif (array_filter($prompts, static fn(string $p): bool => mb_strlen($p) > \SeoControlCenter\Domain\Research\AiAnswerSampler::MAX_PROMPT) !== []) {
                $errors['prompts'] = 'Each prompt can be at most 500 characters.';
            }
            $brand = trim($brand);
            if ($brand === '' || mb_strlen($brand) > 100) {
                $errors['brand'] = 'Enter your brand name (up to 100 characters).';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $params['prompts'] = $prompts;
            $params['brand'] = $brand;
            $params['platforms'] = \SeoControlCenter\Domain\Research\AiAnswerSampler::PLATFORMS;
        }

        $current = $this->results->find($project->id, $kind, $subject);
        if ($current !== null && $current->isQueued()) {
            throw new HttpException(409, 'This lookup is already queued.');
        }
        RepeatedLookup::guard($current, $params, $this->clock->now(), $runAgain, RepeatedLookup::LABELS[$kind]);
        if (!$this->rateLimiter->hit('research:project:' . $project->id, 30, 3600)->allowed) {
            throw new HttpException(429, 'Many lookups were started in the last hour. Please try again later.');
        }
        $this->results->queue($project->id, $project->workspaceId, $kind, $subject, ResumableLookup::resumeParams($current, $params, $this->clock->now()), $user->id);
        $this->scheduler->fetch($project->id, $project->workspaceId, $kind, $subject);
        $this->audit->record(new AuditEvent('research.requested', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'kind' => $kind,
            'locale' => $locale->key(),
            'dropped_seeds' => count($dropped),
            'confirmed' => $confirmed,
            'run_again' => $runAgain,
        ]), $client);

        return $dropped;
    }

    /**
     * Team override of a keyword's relevance, stored as a business-potential
     * rating: relevant = 2, not relevant = 0, auto = the rules again.
     *
     * @return string relevant, not_relevant or auto
     * @throws ValidationException
     */
    public function setRelevance(User $user, Project $project, string $keyword, string $verdict, ClientInfo $client, ?string $via = null): string
    {
        if ($this->business === null) {
            throw new ValidationException(['keyword' => 'Business profiles are not available.']);
        }
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change keyword relevance for this project.');
        }
        $score = match ($verdict) {
            \SeoControlCenter\Domain\Opportunities\Relevance::RELEVANT => 2,
            \SeoControlCenter\Domain\Opportunities\Relevance::NOT_RELEVANT => 0,
            'auto' => null,
            default => throw new ValidationException(['verdict' => 'Choose relevant, not relevant or automatic.']),
        };
        $keyword = \SeoControlCenter\Domain\Keywords\Keyword::normalize($keyword);
        if ($keyword === '' || mb_strlen($keyword) > 255) {
            throw new ValidationException(['keyword' => 'Choose a keyword of up to 255 characters.']);
        }
        $current = $this->business->ratings($project->id)[$keyword] ?? null;
        if ($score === 2 && $current !== null && $current > 0) {
            $score = $current; // keep a finer rating that already says relevant
        }
        $this->business->rate($project->id, $keyword, $score, $user->id);
        $this->audit->record(new AuditEvent('research.relevance_set', $via === null ? ActorType::User : ActorType::Mcp, actorUserId: $user->id, actorLabel: $via ?? $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['verdict' => $verdict, ...($via === null ? [] : ['via' => $via])]), $client);

        return $score === null ? 'auto' : ($score === 0 ? \SeoControlCenter\Domain\Opportunities\Relevance::NOT_RELEVANT : \SeoControlCenter\Domain\Opportunities\Relevance::RELEVANT);
    }

    /** @param list<array{seed: string, reason: string}> $dropped */
    private static function allDropped(array $dropped): string
    {
        return 'Every seed clashes with your business profile, so nothing would be worth paying for: ' . implode('; ', array_map(static fn(array $d): string => '“' . $d['seed'] . '” ' . $d['reason'], $dropped)) . '. Change the seeds, or tick “Run anyway”.';
    }

    /**
     * Adds the chosen research keywords to the project's tracked keywords.
     *
     * @param list<string> $keywords
     * @return int keywords added
     * @throws ValidationException
     */
    public function track(User $user, Project $project, array $keywords, mixed $location, mixed $language, ClientInfo $client): int
    {
        $keywords = array_values(array_filter(array_map(static fn(string $k): string => trim($k), $keywords), static fn(string $k): bool => $k !== ''));
        if ($keywords === []) {
            throw new ValidationException(['keywords' => 'Select at least one keyword.']);
        }

        return $this->keywordService->add($user, $project, implode("\n", $keywords), $location, $language, $client)['added'];
    }

    /**
     * Job: runs a queued lookup and stores its rows.
     *
     * @throws IntegrationException when the provider is temporarily unavailable and $lastAttempt is false
     */
    public function run(int $projectId, int $workspaceId, string $kind, string $subject, bool $lastAttempt): void
    {
        $activeProject = null;
        foreach ($this->projectRepository->allInWorkspace($workspaceId) as $project) {
            if ($project->id === $projectId) {
                $activeProject = $project;
                break;
            }
        }
        if ($activeProject === null || !$this->features->enabled(Feature::KeywordMetrics)) {
            return;
        }
        $result = $this->results->find($projectId, $kind, $subject);
        if ($result === null || !$result->isQueued()) {
            return;
        }
        if ($kind === 'llm' && ($result->params['scheduled'] ?? false) === true && !$this->history->isWeekly($projectId)) {
            $this->results->fail($projectId, $kind, $subject, 'Weekly mention checks were disabled. No provider requests were made.');
            return;
        }
        $locale = $result->locale();
        $domain = is_string($result->params['domain'] ?? null) ? $result->params['domain'] : '';
        if (!in_array($kind, ['ideas', 'suggestions', 'seed_ideas'], true) && self::bareDomain($domain) !== self::bareDomain($activeProject->primaryDomain)) {
            $this->results->fail($projectId, $kind, $subject, 'The project domain changed. Start a new lookup for the current domain. No provider requests were made.');
            return;
        }
        if ($kind === 'gap') {
            $competitor = $this->competitors->find($projectId, $subject);
            $queuedDomain = is_string($result->params['competitor'] ?? null) ? $result->params['competitor'] : '';
            if ($competitor === null || self::bareDomain($competitor->domain) !== self::bareDomain($queuedDomain)) {
                $this->results->fail($projectId, $kind, $subject, 'The competitor was removed or changed. Start a new gap lookup. No provider requests were made.');
                return;
            }
        }
        if (in_array($kind, ['overview', 'llm'], true)) {
            $allowed = [$domain, ...array_map(static fn(Competitor $c): string => $c->domain, $this->competitors->forProject($projectId))];
            $queuedDomains = array_values(array_filter((array) ($result->params['domains'] ?? []), is_string(...)));
            if (array_diff($queuedDomains, $allowed) !== []) {
                $this->results->fail($projectId, $kind, $subject, 'A queued competitor was removed or changed. Start a lookup with the current competitors. No provider requests were made.');
                return;
            }
        }
        try {
            $batch = new ResumableLookup($this->results, $result);
            $rows = match ($kind) {
                'ranked' => array_map(static fn(ResearchKeyword $k): array => $k->toArray(), $this->provider->rankedKeywords($workspaceId, $projectId, $domain, $locale, self::LIMITS['ranked'])),
                'gap' => array_map(static fn(ResearchKeyword $k): array => $k->toArray(), $this->provider->keywordGap($workspaceId, $projectId, $domain, (string) ($result->params['competitor'] ?? ''), $locale, self::LIMITS['gap'])),
                'ideas' => array_map(static fn(ResearchKeyword $k): array => $k->toArray(), $this->provider->keywordIdeas($workspaceId, $projectId, array_values(array_filter((array) ($result->params['seeds'] ?? []), is_string(...))), $locale, self::LIMITS['ideas'])),
                'competitors' => array_map(static fn(ResearchDomain $d): array => $d->toArray(), $this->provider->competitors($workspaceId, $projectId, $domain, $locale, self::LIMITS['competitors'])),
                'overview' => $this->overviewRows($workspaceId, $projectId, array_values(array_filter((array) ($result->params['domains'] ?? []), is_string(...))), $locale, $batch),
                'llm' => $this->mentionRows($workspaceId, $projectId, array_values(array_filter((array) ($result->params['domains'] ?? []), is_string(...))), $locale, $batch),
                'llm_prompts' => $this->answerRows($workspaceId, $projectId, $domain, $locale, $batch),
                'llm_answers' => $this->sampleRows($workspaceId, $projectId, $domain, $result, $batch),
                'suggestions' => $this->suggestionRows($workspaceId, $projectId, $result, $locale, $batch),
                'serp_strength' => $this->strengthRows($workspaceId, $projectId, $result),
                'seed_ideas' => $this->seedRows($workspaceId, $projectId, $domain, $locale),
                default => throw new \InvalidArgumentException('Unknown lookup.'),
            };
        } catch (BudgetExceededException $e) {
            $this->results->fail($projectId, $kind, $subject, $e->getMessage());

            return;
        } catch (IntegrationException $e) {
            if ($e->retryable && !$e->chargeUncertain && !$lastAttempt) {
                throw $e;
            }
            $this->results->fail($projectId, $kind, $subject, $e->getMessage());

            return;
        }
        $this->results->complete($projectId, $kind, $subject, $rows);
        if ($kind === 'llm') {
            $this->history->record($projectId, $workspaceId, $this->clock->now()->format('Y-m-d'), array_values(array_filter(array_map(AiMention::fromArray(...), $rows))));
        }
        $this->logger->info('Research lookup stored', ['project_id' => $projectId, 'kind' => $kind, 'rows' => count($rows)]);
    }


    /**
     * Queues AI seed suggestions from the business profile (the workspace's
     * AI assistant model, in a job). Free of DataForSEO costs; the AI call
     * is reserved against the AI budget.
     *
     * @throws ValidationException
     */
    public function suggestSeeds(User $user, Project $project, mixed $location, mixed $language, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot start analyses for this project.');
        }
        if ($this->seedSuggester === null || !$this->seedSuggester->available($project->workspaceId)) {
            throw new HttpException(409, 'Set up the AI assistant under Integrations first.');
        }
        $context = $this->business?->find($project->id);
        if ($context === null || ($context->description === '' && $context->offerings === [])) {
            throw new ValidationException(['business' => 'Describe the business and what it offers in the business profile first (Opportunities → Business profile).']);
        }
        $locale = KeywordLocale::fromInput($location, $language);
        $current = $this->results->find($project->id, 'seed_ideas');
        if ($current !== null && $current->isQueued()) {
            throw new HttpException(409, 'Seed suggestions are already queued.');
        }
        if (!$this->rateLimiter->hit('research:seeds:project:' . $project->id, 10, 3600)->allowed) {
            throw new HttpException(429, 'Seed suggestions were requested several times in the last hour. Please try again later.');
        }
        $this->results->queue($project->id, $project->workspaceId, 'seed_ideas', '', ['location' => $locale->locationCode, 'language' => $locale->languageCode, 'domain' => self::bareDomain($project->primaryDomain)], $user->id);
        $this->scheduler->fetch($project->id, $project->workspaceId, 'seed_ideas', '');
        $this->audit->record(new AuditEvent('research.requested', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'kind' => 'seed_ideas',
            'locale' => $locale->key(),
        ]), $client);
    }

    /**
     * Competitor-strength check of the stored results pages: domains and the
     * estimated cost, for the opportunities page.
     *
     * @return array{result: ?ResearchResult, domains: int, cost: float, available: bool, canRun: bool}
     */
    public function strengthCheck(User $user, Project $project): array
    {
        $domains = $this->serps !== null && $this->rankings !== null ? count($this->serps->domains($project->id, $this->rankings->settings($project->id)->device, \SeoControlCenter\Domain\Research\DomainStrengthProvider::MAX_DOMAINS)) : 0;

        return [
            'result' => $this->results->find($project->id, 'serp_strength'),
            'domains' => $domains,
            'cost' => $this->strength?->estimatedCost($domains) ?? 0.0,
            'available' => $this->strength?->isAvailable($project->workspaceId) === true && $this->features->enabled(Feature::Backlinks),
            'canRun' => $this->projects->can($user, Permission::AnalysisExecute, $project),
        ];
    }

    /** @return list<string> lowercased, de-duplicated lines or comma-separated values of at most $maxLength characters */
    private static function lines(string $input, int $maxLength): array
    {
        return array_values(array_unique(array_filter(array_map(static fn(string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s))), preg_split('/[\r\n,]+/', $input) ?: []), static fn(string $s): bool => $s !== '' && mb_strlen($s) <= $maxLength)));
    }

    /**
     * Each seed's suggestions, one resumable request per seed; a keyword
     * found for several seeds is kept once.
     *
     * @return list<array<mixed>>
     */
    private function suggestionRows(int $workspaceId, int $projectId, ResearchResult $result, KeywordLocale $locale, ResumableLookup $batch): array
    {
        $rows = [];
        foreach (array_values(array_filter((array) ($result->params['seeds'] ?? []), is_string(...))) as $i => $seed) {
            foreach ($batch->step('seed:' . $i, fn(): array => array_map(static fn(ResearchKeyword $k): array => $k->toArray(), $this->provider->keywordSuggestions($workspaceId, $projectId, $seed, $locale, self::LIMITS['suggestions']))) as $row) {
                $key = is_string($row['k'] ?? null) ? mb_strtolower($row['k']) : '';
                if ($key !== '' && !isset($rows[$key])) {
                    $rows[$key] = $row;
                }
            }
        }

        return array_values($rows);
    }

    /** @return list<array{d: string, r: int}> */
    private function strengthRows(int $workspaceId, int $projectId, ResearchResult $result): array
    {
        if ($this->strength === null) {
            throw new IntegrationException('Competitor strength checks are not available.', false);
        }
        $domains = array_values(array_filter((array) ($result->params['domains'] ?? []), is_string(...)));
        $rows = [];
        foreach ($this->strength->strengths($workspaceId, $projectId, $domains) as $domain => $rank) {
            $rows[] = ['d' => $domain, 'r' => $rank];
        }

        return $rows;
    }

    /** @return list<array{seeds: list<string>, modifiers: list<string>, notes: string}> */
    private function seedRows(int $workspaceId, int $projectId, string $domain, KeywordLocale $locale): array
    {
        $context = $this->business?->find($projectId);
        if ($this->seedSuggester === null || $context === null) {
            throw new IntegrationException('The business profile or the AI assistant is no longer set up. Nothing was sent.', false);
        }

        return [$this->seedSuggester->suggest($workspaceId, $context, $domain, $locale)->toArray()];
    }

    /**
     * The AI assistants section of the AI visibility page.
     *
     * @return array{mentionsResult: ?ResearchResult, answersResult: ?ResearchResult, canRun: bool, canEdit: bool, available: bool, featureOn: bool, costs: array{llm: float, llm_prompts: float, llm_answers_each: float}, locale: KeywordLocale, weekly: bool, history: list<array{date: string, mentions: array<string, array<string, int>>}>, samplesResult: ?ResearchResult, sampling: bool, brand: string, prompts: list<string>}
     */
    public function assistants(User $user, Project $project): array
    {
        $domains = 1 + count($this->competitors->forProject($project->id));
        $platforms = count(AiMentionsProvider::PLATFORMS);
        $samples = $this->results->find($project->id, 'llm_answers');

        return [
            'mentionsResult' => $this->results->find($project->id, 'llm'),
            'answersResult' => $this->results->find($project->id, 'llm_prompts'),
            'canRun' => $this->projects->can($user, Permission::AnalysisExecute, $project),
            'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project),
            'available' => $this->mentions->isAvailable($project->workspaceId),
            'featureOn' => $this->features->enabled(Feature::KeywordMetrics),
            'costs' => [
                'llm' => $this->mentions->estimatedCost($domains * $platforms, $domains * $platforms),
                'llm_prompts' => $this->mentions->estimatedCost($platforms, $platforms * self::ANSWERS_PER_PLATFORM),
                'llm_answers_each' => $this->sampler?->estimatedCost(1) ?? 0.0,
            ],
            'locale' => $this->keywords->lastLocale($project->id) ?? KeywordLocale::default(),
            'weekly' => $this->history->isWeekly($project->id),
            'history' => $this->history->history($project->id, 8),
            'samplesResult' => $samples,
            'sampling' => $this->sampler !== null && $this->sampler->isAvailable($project->workspaceId),
            'brand' => $this->defaultBrand($project, $samples),
            'prompts' => array_values(array_filter((array) ($samples?->params['prompts'] ?? []), is_string(...))),
        ];
    }

    /**
     * Switches the weekly AI mention check on or off (projects.edit).
     */
    public function setWeeklyMentions(User $user, Project $project, bool $weekly, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change the settings of this project.');
        }
        $this->history->setWeekly($project->id, $project->workspaceId, $weekly);
        $this->audit->record(new AuditEvent('research.weekly_mentions_saved', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'weekly' => $weekly,
        ]), $client);
    }

    /**
     * Daily job: queues the weekly AI mention checks that are due.
     *
     * @return int checks queued
     */
    public function startDueMentions(): int
    {
        if (!$this->features->enabled(Feature::KeywordMetrics)) {
            return 0;
        }
        $queued = 0;
        foreach ($this->history->dueWeekly($this->clock->now()->modify('-' . self::WEEK_DAYS . ' days')->format('Y-m-d')) as $row) {
            $project = $this->projectRepository->findInWorkspace($row['workspaceId'], $row['projectPublicId']);
            if ($project === null || $project->id !== $row['projectId'] || !$this->mentions->isAvailable($project->workspaceId)) {
                continue;
            }
            if ($this->results->find($project->id, 'llm')?->isQueued() ?? false) {
                continue;
            }
            $locale = $this->keywords->lastLocale($project->id) ?? KeywordLocale::default();
            $domain = self::bareDomain($project->primaryDomain);
            $params = ResumableLookup::scheduledParams($this->results->find($project->id, 'llm'), [
                'scheduled' => true,
                'location' => $locale->locationCode,
                'language' => $locale->languageCode,
                'domain' => $domain,
                'domains' => [$domain, ...array_map(static fn(Competitor $c): string => $c->domain, $this->competitors->forProject($project->id))],
            ]);
            if ($params === null) {
                continue;
            }
            $this->results->queue($project->id, $project->workspaceId, 'llm', '', $params, null);
            $this->scheduler->fetch($project->id, $project->workspaceId, 'llm', '');
            $queued++;
        }

        return $queued;
    }

    /**
     * @param list<string> $domains
     * @return list<array<mixed>>
     */
    private function overviewRows(int $workspaceId, int $projectId, array $domains, KeywordLocale $locale, ResumableLookup $batch): array
    {
        $rows = [];
        foreach (array_unique($domains) as $domain) {
            array_push($rows, ...$batch->step('overview:' . $domain, fn(): array => array_map(static fn(ResearchDomain $d): array => $d->toArray(), $this->provider->overview($workspaceId, $projectId, [$domain], $locale))));
        }
        return $rows;
    }

    /** @param list<string> $domains
     * @return list<array<mixed>>
     */
    private function mentionRows(int $workspaceId, int $projectId, array $domains, KeywordLocale $locale, ResumableLookup $batch): array
    {
        $rows = [];
        foreach (AiMentionsProvider::PLATFORMS as $platform) {
            foreach (array_unique($domains) as $domain) {
                array_push($rows, ...$batch->step('mentions:' . $platform . ':' . $domain, fn(): array => array_map(static fn(AiMention $m): array => $m->toArray(), $this->mentions->metrics($workspaceId, $projectId, [$domain], $platform, $locale))));
            }
        }

        return $rows;
    }

    /**
     * @return list<array<mixed>>
     */
    private function answerRows(int $workspaceId, int $projectId, string $domain, KeywordLocale $locale, ResumableLookup $batch): array
    {
        $rows = [];
        foreach (AiMentionsProvider::PLATFORMS as $platform) {
            array_push($rows, ...$batch->step('answers:' . $platform, fn(): array => array_map(static fn(\SeoControlCenter\Domain\Research\AiAnswer $a): array => $a->toArray(), $this->mentions->answers($workspaceId, $projectId, $domain, $platform, $locale, self::ANSWERS_PER_PLATFORM))));
        }

        return $rows;
    }

    /** The brand to look for: the last check's, the brand-mention setting, or the domain's main label. */
    private function defaultBrand(Project $project, ?ResearchResult $samples): string
    {
        $last = $samples !== null && is_string($samples->params['brand'] ?? null) ? $samples->params['brand'] : null;
        $setting = $this->brands?->settings($project);

        return $last ?? ($setting !== null ? $setting->name : explode('.', self::bareDomain($project->primaryDomain))[0]);
    }

    /**
     * Each prompt sent to each platform, one resumable step per answer so a
     * retry never pays again for answers already received.
     *
     * @return list<array<string, mixed>>
     */
    private function sampleRows(int $workspaceId, int $projectId, string $domain, ResearchResult $result, ResumableLookup $batch): array
    {
        if ($this->sampler === null) {
            throw new IntegrationException('AI answer checks are not available.', false);
        }
        $prompts = array_values(array_filter((array) ($result->params['prompts'] ?? []), is_string(...)));
        $brand = is_string($result->params['brand'] ?? null) ? $result->params['brand'] : '';
        $competitors = array_map(static fn(Competitor $c): string => self::bareDomain($c->domain), $this->competitors->forProject($projectId));
        $rows = [];
        foreach (\SeoControlCenter\Domain\Research\AiAnswerSampler::PLATFORMS as $platform) {
            foreach ($prompts as $i => $prompt) {
                array_push($rows, ...$batch->step('answer:' . $platform . ':' . $i, fn(): array => [
                    self::analyseAnswer($this->sampler->answer($workspaceId, $projectId, $platform, $prompt), $domain, $brand, $competitors),
                ]));
            }
        }
        return $rows;
    }

    /**
     * What one sampled answer says about the project: whether it names the
     * brand, cites the project's site, and which competitors it names or cites.
     *
     * @param list<string> $competitors bare competitor domains
     * @return array<string, mixed>
     */
    public static function analyseAnswer(\SeoControlCenter\Domain\Research\SampledAnswer $answer, string $domain, string $brand, array $competitors): array
    {
        $own = self::bareDomain($domain);
        $hosts = $answer->citedHosts();
        $cites = static fn(string $site): bool => array_any($hosts, static fn(string $h): bool => $h === $site || str_ends_with($h, '.' . $site));
        $names = static fn(string $term): bool => $term !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu', $answer->text) === 1;
        $label = static fn(string $site): string => explode('.', $site)[0];
        $rivals = [];
        foreach (array_values(array_unique($competitors)) as $competitor) {
            $mentioned = $names($competitor) || $names($label($competitor));
            $cited = $cites($competitor);
            if ($mentioned || $cited) {
                $rivals[] = ['domain' => $competitor, 'mentioned' => $mentioned, 'cited' => $cited];
            }
        }

        return [
            'prompt' => $answer->prompt,
            'platform' => $answer->platform,
            'model' => $answer->model,
            'web_search' => $answer->webSearch,
            'brand_mentioned' => $names($brand) || $names($own),
            'own_cited' => $cites($own),
            'competitors' => $rivals,
            'citations' => $answer->citations,
            'text' => $answer->text,
        ];
    }

    public static function bareDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }
}
