<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Mcp\Server\BoundedResult;
use SeoControlCenter\Modules\Mcp\Server\McpServer;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Opportunities\OpportunityService;
use SeoControlCenter\Modules\SiteAudit\SiteAuditService;

/**
 * The assistant's tools (ADR-028):
 *
 * - the read-only MCP tools, run with the signed-in user's project visibility;
 * - `fix_plan_save`, which stores a per-page list of changes the user
 *   downloads as CSV (it changes no data, so it runs without confirmation);
 * - two changes the model can only propose and the user confirms in the
 *   chat: `opportunity_set_status` and `site_audit_start` (a free re-crawl
 *   to check fixes). They run through the regular services, with their
 *   permission checks and audit log entries.
 *
 * Oversized results are bounded as valid JSON with explicit truncation metadata.
 */
final readonly class AssistantTools
{
    public const string SET_STATUS = 'opportunity_set_status';
    public const string START_AUDIT = 'site_audit_start';
    public const string FIX_PLAN = 'fix_plan_save';
    public const int MAX_RESULT = 16000;
    private const array CONFIRMED = [self::SET_STATUS, self::START_AUDIT];
    private const array STATUSES = [StoredOpportunity::DONE, StoredOpportunity::DISMISSED, StoredOpportunity::OPEN];

    public function __construct(
        private McpServer $mcp,
        private OpportunityRepository $opportunities,
        private OpportunityService $opportunityService,
        private SiteAuditService $siteAudits,
        private FixPlanRepository $fixPlans,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(ToolContext $context): array
    {
        $project = ['type' => 'string', 'description' => 'The project, from projects_list.'];
        $tools = array_map(static fn(McpTool $t): array => [
            'name' => $t->name(),
            'description' => $t->description(),
            'input_schema' => $t->inputSchema(),
        ], $this->mcp->tools($context));
        $tools[] = [
            'name' => self::FIX_PLAN,
            'description' => 'Saves a fix plan: one row per page with the URL, the issue, the current value, the exact suggested change and a short note, for whoever edits the website. The user gets a CSV download link in the chat. Use it whenever you suggest concrete changes for more than a few pages (for example new titles for duplicate titles), with the current values from site_audit. Keep suggested values ready to paste.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'project_id' => $project,
                    'title' => ['type' => 'string', 'description' => 'Short name, e.g. "New titles for duplicate product pages".'],
                    'rows' => [
                        'type' => 'array',
                        'maxItems' => FixPlan::MAX_ROWS,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => ['type' => 'string'],
                                'issue' => ['type' => 'string'],
                                'current' => ['type' => 'string', 'description' => 'The current value, e.g. the current title.'],
                                'suggested' => ['type' => 'string', 'description' => 'The exact new value or the action to take.'],
                                'note' => ['type' => 'string'],
                            ],
                            'required' => ['url', 'suggested'],
                        ],
                    ],
                ],
                'required' => ['project_id', 'title', 'rows'],
            ],
        ];
        $tools[] = [
            'name' => self::SET_STATUS,
            'description' => 'Proposes to change the status of an opportunity: `done` (the user has acted on it), `dismissed` (not relevant) or `open` (reopen). The change only happens after the user confirms it in the chat, so call this only when the user asked for it. Take opportunity_id from opportunities_list.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'project_id' => $project,
                    'opportunity_id' => ['type' => 'string', 'description' => 'The opportunity, from opportunities_list.'],
                    'status' => ['type' => 'string', 'enum' => self::STATUSES],
                ],
                'required' => ['project_id', 'opportunity_id', 'status'],
            ],
        ];
        $tools[] = [
            'name' => self::START_AUDIT,
            'description' => 'Proposes to start a new site audit of the project, for example to check whether technical issues were fixed. It is free (the crawler runs on this server) and starts after the user confirms; the crawl runs in the background and takes a few minutes, so tell the user to ask again when it has finished. Only one audit per project runs at a time.',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['project_id' => $project],
                'required' => ['project_id'],
            ],
        ];

        return $tools;
    }

    /**
     * Whether the user has to confirm the call before it runs.
     */
    public function isWrite(string $name): bool
    {
        return in_array($name, self::CONFIRMED, true);
    }

    /**
     * Short label for the chat while a tool runs, e.g. "Opportunities".
     */
    public function label(ToolContext $context, string $name): string
    {
        foreach ($this->mcp->tools($context) as $tool) {
            if ($tool->name() === $name) {
                return $tool->title();
            }
        }

        return match ($name) {
            self::SET_STATUS => 'Opportunity status',
            self::START_AUDIT => 'New site audit',
            self::FIX_PLAN => 'Fix plan',
            default => $name,
        };
    }

    /**
     * Runs a tool that needs no confirmation (the MCP tools and the fix plan).
     *
     * @param array<string, mixed> $input
     * @return array{content: string, is_error: bool}
     */
    public function run(ToolContext $context, string $name, array $input): array
    {
        try {
            if ($name === self::FIX_PLAN) {
                return ['content' => self::json($this->saveFixPlan($context, $input)), 'is_error' => false];
            }
            foreach ($this->mcp->tools($context) as $tool) {
                if ($tool->name() === $name) {
                    return ['content' => self::json(BoundedResult::limit($tool->call($context, $input), self::MAX_RESULT)), 'is_error' => false];
                }
            }
        } catch (ToolError $e) {
            return ['content' => $e->getMessage(), 'is_error' => true];
        } catch (\Throwable $e) {
            $this->logger->error('Assistant tool failed', ['tool' => $name, 'exception' => $e]);

            return ['content' => 'The tool failed on the server.', 'is_error' => true];
        }

        return ['content' => 'Unknown tool: ' . mb_substr($name, 0, 60), 'is_error' => true];
    }

    /** @param array<string, mixed> $input */
    public function canRead(ToolContext $context, string $name, array $input): bool
    {
        try {
            if (isset($input['project_id'])) {
                $context->project($input);
            }
            // Budget reads have an additional live role check inside the tool.
            // Re-evaluate it before replay after an administrator loses that role.
            if ($name === 'project_monitoring' && ($input['kind'] ?? null) === 'budget') {
                return !$this->run($context, $name, $input)['is_error'];
            }
            return in_array($name, array_column($this->definitions($context), 'name'), true);
        } catch (ToolError) {
            return false;
        }
    }

    /**
     * What the user is asked to confirm, e.g. "Mark “Move …” as done".
     *
     * @param array<string, mixed> $input
     * @throws ToolError when the target cannot be found or the input is invalid
     */
    public function describe(ToolContext $context, string $name, array $input): string
    {
        if ($name === self::START_AUDIT) {
            $project = $context->project($input);

            return sprintf('Start a new site audit of %s (free, takes a few minutes)', $project->primaryDomain);
        }
        [, $opportunity, $status] = $this->opportunityTarget($context, $input);

        return sprintf(match ($status) {
            StoredOpportunity::DONE => 'Mark “%s” as done',
            StoredOpportunity::DISMISSED => 'Dismiss “%s”',
            default => 'Reopen “%s”',
        }, $opportunity->title);
    }

    /**
     * Runs a confirmed change through the regular service (permission check, audit log).
     *
     * @param array<string, mixed> $input
     * @return array{content: string, is_error: bool}
     */
    public function write(ToolContext $context, User $user, string $name, array $input, ClientInfo $client): array
    {
        try {
            if ($name === self::START_AUDIT) {
                $project = $context->project($input);
                $audit = $this->siteAudits->start($user, $project, $client);

                return ['content' => sprintf('Started a site audit of %s (up to %d pages). It runs in the background; the results are ready in a few minutes under Site audit, and the opportunities update after the next analysis.', $project->primaryDomain, $audit->maxPages), 'is_error' => false];
            }
            [$project, $opportunity, $status] = $this->opportunityTarget($context, $input);
            $this->opportunityService->setStatus($user, $project, $opportunity->publicId, $status, $client);

            return ['content' => sprintf('Done: “%s” is now %s.', $opportunity->title, $status), 'is_error' => false];
        } catch (ToolError $e) {
            return ['content' => $e->getMessage(), 'is_error' => true];
        } catch (HttpException $e) {
            return ['content' => 'Not possible: ' . $e->getMessage(), 'is_error' => true];
        } catch (\Throwable $e) {
            $this->logger->warning('Assistant write failed', ['tool' => $name, 'exception' => $e]);

            return ['content' => 'The change failed on the server.', 'is_error' => true];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{plan_id: string, title: string, rows: int, download_url: string}
     */
    private function saveFixPlan(ToolContext $context, array $input): array
    {
        $project = $context->project($input);
        $rows = FixPlan::rowsFrom($input['rows'] ?? null);
        if ($rows === []) {
            throw new ToolError('rows must contain at least one row with a url or a suggested change.');
        }
        $title = is_string($input['title'] ?? null) && trim($input['title']) !== '' ? trim($input['title']) : 'Fix plan for ' . $project->primaryDomain;
        $plan = $this->fixPlans->save($context->access->token->workspaceId, $project->id, $context->access->user->id, $title, $rows);

        return ['plan_id' => $plan->publicId, 'title' => $plan->title, 'rows' => count($rows), 'download_url' => '/assistant/plans/' . $plan->publicId];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: Project, 1: StoredOpportunity, 2: string}
     */
    private function opportunityTarget(ToolContext $context, array $input): array
    {
        $project = $context->project($input);
        $status = ToolContext::choice($input, 'status', '', self::STATUSES);
        $id = is_string($input['opportunity_id'] ?? null) ? $input['opportunity_id'] : '';
        $opportunity = $this->opportunities->find($project->id, $id) ?? throw new ToolError('Opportunity not found. Take opportunity_id from opportunities_list.');

        return [$project, $opportunity, $status];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

}
