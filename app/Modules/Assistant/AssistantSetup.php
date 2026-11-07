<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Integrations\UsageSummary;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Workspaces\Workspace;

/**
 * Integrations → AI assistant: the workspace's provider (Claude or GPT),
 * its API key (verified, stored encrypted), the model and the budget
 * (ADR-028). One budget covers the assistant, whichever provider it uses.
 */
final readonly class AssistantSetup
{
    /** The assistant's spending in the usage ledger, for every model provider. */
    public const string PROVIDER = 'ai_assistant';
    private const float MAX_AMOUNT = 100000.0;

    public function __construct(
        private AssistantSettingsRepository $settings,
        private LanguageModels $models,
        private UsageLedger $ledger,
        private Authorizer $authorizer,
        private FeatureManager $features,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{settings: ?AssistantSettings, providers: array<string, string>, models: array<string, array<string, string>>, budget: ApiBudget, usage: UsageSummary, canManage: bool, featureOn: bool}
     */
    public function overview(User $user, Workspace $workspace): array
    {
        $this->authorizer->authorize($user->subject(), Permission::WorkspaceView, $workspace->id);

        return [
            'settings' => $this->settings->find($workspace->id),
            'providers' => $this->models->providers(),
            'models' => $this->models->models(),
            'budget' => $this->ledger->budget($workspace->id, self::PROVIDER),
            'usage' => $this->ledger->summary($workspace->id, self::PROVIDER),
            'canManage' => $this->authorizer->can($user->subject(), Permission::IntegrationsManage, $workspace->id),
            'featureOn' => $this->features->enabled(Feature::Mcp),
        ];
    }

    /**
     * Checks the key with the provider and stores it encrypted.
     *
     * @throws ValidationException when the input is invalid or the provider rejects the key
     * @throws IntegrationException when the provider cannot be reached
     */
    public function connect(User $user, Workspace $workspace, #[SensitiveParameter] string $apiKey, string $model, ClientInfo $client, string $providerWorkspace = ''): void
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        if (!$this->rateLimiter->hit('assistant:connect:user:' . $user->id, 10, 600)->allowed) {
            throw new HttpException(429, 'Too many attempts. Try again in a few minutes.');
        }
        $apiKey = trim($apiKey);
        $errors = [];
        $provider = $this->models->providerOf($model);
        if ($provider === null) {
            $errors['model'] = 'Choose a model.';
        }
        if ($apiKey === '' || strlen($apiKey) > 300 || preg_match('/^[A-Za-z0-9_\-]+$/D', $apiKey) !== 1) {
            $errors['api_key'] = $provider === 'openai' ? 'Enter your OpenAI API key (it starts with sk-).' : 'Enter your Claude API key (it starts with sk-ant-).';
        }
        // Only Claude keys that can act in several workspaces need one; GPT keys never send it.
        $providerWorkspace = trim($providerWorkspace);
        if ($providerWorkspace === '' || $provider !== 'anthropic') {
            $providerWorkspace = null;
        } elseif (preg_match('/^wrkspc_[A-Za-z0-9]{1,57}$/D', $providerWorkspace) !== 1) {
            $errors['provider_workspace'] = 'Enter the workspace ID from Claude Console → Settings → Workspaces (it starts with wrkspc_), or leave it empty.';
        }
        if ($errors !== [] || $provider === null) {
            throw new ValidationException($errors);
        }
        try {
            $this->models->get($provider)->verify($apiKey, $model, $providerWorkspace);
        } catch (IntegrationException $e) {
            if ($e->retryable) {
                throw $e;
            }
            throw new ValidationException(['api_key' => $e->getMessage()]);
        }
        $this->settings->save($workspace->id, $provider, $apiKey, $model, $user->id, $providerWorkspace);
        $this->audit->record(new AuditEvent('integration.assistant.connected', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'assistant', metadata: ['provider' => $provider, 'model' => $model, 'provider_workspace' => $providerWorkspace]), $client);
    }

    public function setModel(User $user, Workspace $workspace, string $model, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        $settings = $this->settings->find($workspace->id) ?? throw new HttpException(409, 'Add an API key first.');
        if (!isset($this->models->get($settings->provider)->models()[$model])) {
            throw new ValidationException(['model' => 'Choose a model.']);
        }
        $this->settings->setModel($workspace->id, $model);
        $this->audit->record(new AuditEvent('integration.assistant.model_changed', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'assistant', metadata: ['model' => $model]), $client);
    }

    /**
     * @param array<string, mixed> $input daily_limit, monthly_limit, operation_limit (empty = no limit)
     * @throws ValidationException
     */
    public function saveBudget(User $user, Workspace $workspace, array $input, ClientInfo $client): ApiBudget
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        $errors = [];
        $daily = self::amount($input, 'daily_limit', $errors);
        $monthly = self::amount($input, 'monthly_limit', $errors);
        $operation = self::amount($input, 'operation_limit', $errors);
        if ($daily !== null && $monthly !== null && $daily > $monthly) {
            $errors['daily_limit'] = 'The daily budget cannot be higher than the monthly budget.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $budget = new ApiBudget($daily, $monthly, $operation);
        $this->ledger->saveBudget($workspace->id, self::PROVIDER, $budget, $user->id);
        $this->audit->record(new AuditEvent('integration.assistant.budget_updated', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'assistant', metadata: [
            'daily_limit' => $daily,
            'monthly_limit' => $monthly,
            'operation_limit' => $operation,
        ]), $client);

        return $budget;
    }

    /**
     * Deletes the key. Conversations, usage history and budgets are kept.
     */
    public function disconnect(User $user, Workspace $workspace, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        if ($this->settings->find($workspace->id) === null) {
            throw new HttpException(409, 'The AI assistant is not set up.');
        }
        $this->settings->delete($workspace->id);
        $this->audit->record(new AuditEvent('integration.assistant.disconnected', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'assistant'), $client);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private static function amount(array $input, string $field, array &$errors): ?float
    {
        $raw = is_string($input[$field] ?? null) ? trim(str_replace(',', '.', $input[$field])) : '';
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{1,6}(\.\d{1,4})?$/D', $raw) === 1 && (float) $raw <= self::MAX_AMOUNT) {
            return (float) $raw;
        }
        $errors[$field] = 'Enter an amount in USD, e.g. 5 or 2.50, or leave empty for no limit.';

        return null;
    }
}
