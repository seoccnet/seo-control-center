<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Assistant\Conversation;
use SeoControlCenter\Modules\Assistant\ConversationRepository;

final readonly class PdoConversationRepository implements ConversationRepository
{
    private const string COLUMNS = 'id, public_id, workspace_id, user_id, pending, model_calls, updated_at';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function lock(int $workspaceId, int $userId): ?\Closure
    {
        $lock = new LeaseLock($this->db, 'assistant:' . $workspaceId . ':' . $userId, 180);

        return $lock->acquire() ? static fn() => $lock->release() : null;
    }

    public function latest(int $workspaceId, int $userId): ?Conversation
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM assistant_conversations WHERE workspace_id = ? AND user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1', [$workspaceId, $userId]);

        return $row === null ? null : self::conversation($row);
    }

    public function byPublicId(int $workspaceId, int $userId, string $publicId): ?Conversation
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM assistant_conversations WHERE workspace_id = ? AND user_id = ? AND public_id = ?', [$workspaceId, $userId, $publicId]);

        return $row === null ? null : self::conversation($row);
    }

    public function create(int $workspaceId, int $userId): Conversation
    {
        $now = $this->now();
        $publicId = Uuid::v7($this->clock);
        $this->db->execute(
            'INSERT INTO assistant_conversations (public_id, workspace_id, user_id, pending, model_calls, created_at, updated_at) VALUES (?, ?, ?, NULL, 0, ?, ?)',
            [$publicId, $workspaceId, $userId, $now, $now],
        );

        return new Conversation($this->db->lastInsertId(), $publicId, $workspaceId, $userId, null, 0, $now);
    }

    public function messages(int $conversationId, int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT role, content FROM (SELECT id, role, content FROM assistant_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit) . ') m ORDER BY id',
            [$conversationId],
        );
        $messages = [];
        foreach ($rows as $row) {
            $content = json_decode((string) $row['content'], true);
            if (is_array($content) && array_is_list($content)) {
                /** @var list<array<string, mixed>> $content */
                $messages[] = ['role' => (string) $row['role'], 'content' => $content];
            }
        }

        return $messages;
    }

    public function append(int $conversationId, string $role, array $content, ?int $inputTokens = null, ?int $outputTokens = null): void
    {
        $now = $this->now();
        $this->db->execute(
            'INSERT INTO assistant_messages (conversation_id, role, content, input_tokens, output_tokens, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$conversationId, $role, json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $inputTokens, $outputTokens, $now],
        );
        $this->db->execute('UPDATE assistant_conversations SET updated_at = ? WHERE id = ?', [$now, $conversationId]);
    }

    public function update(int $conversationId, ?array $pending, int $modelCalls): void
    {
        $this->db->execute(
            'UPDATE assistant_conversations SET pending = ?, model_calls = ?, updated_at = ? WHERE id = ?',
            [$pending !== null ? json_encode($pending, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $modelCalls, $this->now(), $conversationId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function conversation(array $row): Conversation
    {
        $pending = $row['pending'] !== null ? json_decode((string) $row['pending'], true) : null;

        return new Conversation(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (int) $row['user_id'],
            is_array($pending) ? Conversation::pendingFrom($pending) : null,
            (int) $row['model_calls'],
            (string) $row['updated_at'],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
