<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO;

use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
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
use SeoControlCenter\Modules\DataForSEO\Api\AccountInfo;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;

/**
 * Application service for a workspace's DataForSEO account: connect with
 * API credentials, verify, budgets and disconnect. Authorization, rate
 * limiting and audit live here.
 */
final readonly class DataForSeoService
{
    private const float MAX_AMOUNT = 1000000.0;

    public function __construct(
        private DataForSeoProfileRepository $profiles,
        private DataForSeoClient $client,
        private UsageLedger $ledger,
        private Authorizer $authorizer,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{profile: ?DataForSeoProfile, budget: ApiBudget, usage: UsageSummary, canManage: bool, sandbox: bool}
     */
    public function overview(User $user, Workspace $workspace): array
    {
        $this->authorizer->authorize($user->subject(), Permission::WorkspaceView, $workspace->id);

        return [
            'profile' => $this->profiles->find($workspace->id),
            'budget' => $this->ledger->budget($workspace->id, DataForSeoClient::PROVIDER),
            'usage' => $this->ledger->summary($workspace->id, DataForSeoClient::PROVIDER),
            'canManage' => $this->authorizer->can($user->subject(), Permission::IntegrationsManage, $workspace->id),
            'sandbox' => $this->client->isSandbox(),
        ];
    }

    /**
     * Verifies the credentials against DataForSEO and stores them encrypted.
     *
     * @throws ValidationException when the input is invalid or DataForSEO rejects the credentials
     * @throws IntegrationException when DataForSEO cannot be reached
     */
    public function connect(User $user, Workspace $workspace, string $login, #[SensitiveParameter] string $password, ClientInfo $client): AccountInfo
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        $login = trim($login);
        $errors = [];
        if ($login === '' || mb_strlen($login) > 191 || preg_match('/[\x00-\x20:]/', $login) === 1) {
            $errors['login'] = 'Enter the API login from the DataForSEO dashboard (usually your e-mail address).';
        }
        if ($password === '' || strlen($password) > 255) {
            $errors['password'] = 'Enter the API password from the DataForSEO dashboard (not your account password).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->throttle($user);

        $credentials = new DataForSeoCredentials($login, $password);
        try {
            $info = $this->client->accountInfo($credentials);
        } catch (IntegrationException $e) {
            if ($e->authorizationLost) {
                throw new ValidationException(['password' => 'DataForSEO rejected this API login or password.']);
            }
            throw $e;
        }

        $this->profiles->save($workspace->id, $credentials, $info->balance, $user->id);
        $this->audit->record(new AuditEvent('integration.dataforseo.connected', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'dataforseo', metadata: [
            'login' => $login,
            'sandbox' => $this->client->isSandbox(),
        ]), $client);

        return $info;
    }

    /**
     * Re-checks the stored credentials and refreshes the balance.
     *
     * @throws IntegrationException
     */
    public function verify(User $user, Workspace $workspace): AccountInfo
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        $credentials = $this->profiles->credentials($workspace->id) ?? throw new HttpException(409, 'DataForSEO is not connected.');
        $this->throttle($user);

        try {
            $info = $this->client->accountInfo($credentials);
        } catch (IntegrationException $e) {
            if ($e->authorizationLost) {
                $this->profiles->setError($workspace->id, $e->getMessage());
            }
            throw $e;
        }
        $this->profiles->recordCheck($workspace->id, $info->balance);

        return $info;
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
        $this->ledger->saveBudget($workspace->id, DataForSeoClient::PROVIDER, $budget, $user->id);
        $this->audit->record(new AuditEvent('integration.dataforseo.budget_updated', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'dataforseo', metadata: [
            'daily_limit' => $daily,
            'monthly_limit' => $monthly,
            'operation_limit' => $operation,
        ]), $client);

        return $budget;
    }

    /**
     * Deletes the stored credentials. Usage history and budgets are kept.
     */
    public function disconnect(User $user, Workspace $workspace, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        $profile = $this->profiles->find($workspace->id) ?? throw new HttpException(409, 'DataForSEO is not connected.');
        $this->profiles->delete($workspace->id);
        $this->audit->record(new AuditEvent('integration.dataforseo.disconnected', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'dataforseo', metadata: [
            'login' => $profile->login,
        ]), $client);
    }

    public static function describe(IntegrationException $e): string
    {
        return $e->retryable
            ? 'DataForSEO could not be reached right now. Please try again in a few minutes.'
            : $e->getMessage();
    }

    /**
     * Parses an optional USD amount; empty means "no limit".
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private static function amount(array $input, string $field, array &$errors): ?float
    {
        if (array_key_exists($field, $input) && !is_string($input[$field])) {
            $errors[$field] = 'Enter an amount in USD, e.g. 5 or 2.50, or leave empty for no limit.';

            return null;
        }
        $raw = is_string($input[$field] ?? null) ? trim(str_replace(',', '.', $input[$field])) : '';
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{1,7}(\.\d{1,4})?$/D', $raw) === 1 && (float) $raw <= self::MAX_AMOUNT) {
            return (float) $raw;
        }
        $errors[$field] = 'Enter an amount in USD, e.g. 5 or 2.50, or leave empty for no limit.';

        return null;
    }

    private function throttle(User $user): void
    {
        if (!$this->rateLimiter->hit('dataforseo:check:user:' . $user->id, 10, 600)->allowed) {
            throw new HttpException(429, 'Too many DataForSEO checks. Try again in a few minutes.');
        }
    }
}
