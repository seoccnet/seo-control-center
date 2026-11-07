<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SensitiveParameter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Assistant\AssistantSettings;
use SeoControlCenter\Modules\Assistant\AssistantSettingsRepository;
use SeoControlCenter\Modules\Assistant\Conversation;
use SeoControlCenter\Modules\Assistant\ConversationRepository;

/**
 * AI assistant settings (key kept in plain text here) and conversations in memory.
 */
final class InMemoryAssistant implements AssistantSettingsRepository, ConversationRepository
{
    /** @var array<int, array{provider: string, key: string, model: string, status: string, error: ?string, workspace?: ?string}> */
    public array $settings = [];

    /** @var array<int, Conversation> */
    public array $conversations = [];

    /** @var array<int, list<array{role: string, content: list<array<string, mixed>>}>> */
    public array $messages = [];

    /** @var array<string, bool> */
    private array $locks = [];

    public function lock(int $workspaceId, int $userId): ?\Closure
    {
        $key = $workspaceId . ':' . $userId;
        if (isset($this->locks[$key])) {
            return null;
        }
        $this->locks[$key] = true;

        return function () use ($key): void {
            unset($this->locks[$key]);
        };
    }

    public function __construct(private readonly Clock $clock) {}

    public function find(int $workspaceId): ?AssistantSettings
    {
        $s = $this->settings[$workspaceId] ?? null;

        return $s === null ? null : new AssistantSettings($workspaceId, $s['provider'], $s['model'], $s['status'], $s['error'], '2026-10-01 00:00:00', $s['workspace'] ?? null);
    }

    public function apiKey(int $workspaceId): ?string
    {
        return $this->settings[$workspaceId]['key'] ?? null;
    }

    public function save(int $workspaceId, string $provider, #[SensitiveParameter] string $apiKey, string $model, int $userId, ?string $providerWorkspace = null): void
    {
        $this->settings[$workspaceId] = ['provider' => $provider, 'key' => $apiKey, 'model' => $model, 'status' => 'active', 'error' => null, 'workspace' => $providerWorkspace];
    }

    public function setModel(int $workspaceId, string $model): void
    {
        $this->settings[$workspaceId]['model'] = $model;
    }

    public function setError(int $workspaceId, ?string $error): void
    {
        if (isset($this->settings[$workspaceId])) {
            $this->settings[$workspaceId]['status'] = $error === null ? 'active' : 'error';
            $this->settings[$workspaceId]['error'] = $error;
        }
    }

    public function delete(int $workspaceId): void
    {
        unset($this->settings[$workspaceId]);
    }

    public function latest(int $workspaceId, int $userId): ?Conversation
    {
        $found = null;
        foreach ($this->conversations as $c) {
            if ($c->workspaceId === $workspaceId && $c->userId === $userId) {
                $found = $c; // ids grow, the last one is the newest
            }
        }

        return $found;
    }

    public function byPublicId(int $workspaceId, int $userId, string $publicId): ?Conversation
    {
        foreach ($this->conversations as $c) {
            if ($c->workspaceId === $workspaceId && $c->userId === $userId && $c->publicId === $publicId) {
                return $c;
            }
        }

        return null;
    }

    public function create(int $workspaceId, int $userId): Conversation
    {
        $id = count($this->conversations) + 1;

        return $this->conversations[$id] = new Conversation($id, Uuid::v7(), $workspaceId, $userId, null, 0, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function messages(int $conversationId, int $limit): array
    {
        return array_slice($this->messages[$conversationId] ?? [], -$limit);
    }

    public function append(int $conversationId, string $role, array $content, ?int $inputTokens = null, ?int $outputTokens = null): void
    {
        // Stored as JSON in the database: keep the same round trip.
        $content = json_decode((string) json_encode($content), true);
        /** @var list<array<string, mixed>> $content */
        $this->messages[$conversationId][] = ['role' => $role, 'content' => $content];
    }

    public function update(int $conversationId, ?array $pending, int $modelCalls): void
    {
        $c = $this->conversations[$conversationId];
        $this->conversations[$conversationId] = new Conversation($c->id, $c->publicId, $c->workspaceId, $c->userId, $pending, $modelCalls, $c->updatedAt);
    }
}
