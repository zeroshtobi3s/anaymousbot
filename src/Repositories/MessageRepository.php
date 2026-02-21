<?php

declare(strict_types=1);

namespace PhpBot\Repositories;

use DateTimeImmutable;
use PDO;

final class MessageRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(
        int $targetUserId,
        int $senderTelegramUserId,
        string $threadId,
        string $messageType,
        ?string $text,
        ?string $mediaFileId,
        string $contentHash
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO messages (
                target_user_id,
                sender_telegram_user_id,
                thread_id,
                message_type,
                text,
                media_file_id,
                content_hash,
                created_at,
                is_deleted
             ) VALUES (
                :target_user_id,
                :sender_telegram_user_id,
                :thread_id,
                :message_type,
                :text,
                :media_file_id,
                :content_hash,
                NOW(),
                0
             )'
        );
        $statement->execute([
            'target_user_id' => $targetUserId,
            'sender_telegram_user_id' => $senderTelegramUserId,
            'thread_id' => $threadId,
            'message_type' => $messageType,
            'text' => $text,
            'media_file_id' => $mediaFileId,
            'content_hash' => $contentHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $messageId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, target_user_id, sender_telegram_user_id, thread_id, message_type, text, media_file_id, created_at
             FROM messages
             WHERE id = :id AND is_deleted = 0
             LIMIT 1'
        );
        $statement->execute(['id' => $messageId]);
        $result = $statement->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getInbox(int $targetUserId, int $limit = 10): array
    {
        $safeLimit = max(1, min($limit, 50));
        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, message_type, text, media_file_id, created_at
                 FROM messages
                 WHERE target_user_id = :target_user_id AND is_deleted = 0
                 ORDER BY id DESC
                 LIMIT %d',
                $safeLimit
            )
        );
        $statement->execute(['target_user_id' => $targetUserId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function countBySenderSince(int $senderTelegramUserId, DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM messages
             WHERE sender_telegram_user_id = :sender_telegram_user_id
               AND created_at >= :since
               AND is_deleted = 0'
        );
        $statement->execute([
            'sender_telegram_user_id' => $senderTelegramUserId,
            'since' => $since->format('Y-m-d H:i:s'),
        ]);

        return (int) $statement->fetchColumn();
    }

    public function countByTargetSince(int $targetUserId, DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM messages
             WHERE target_user_id = :target_user_id
               AND created_at >= :since
               AND is_deleted = 0'
        );
        $statement->execute([
            'target_user_id' => $targetUserId,
            'since' => $since->format('Y-m-d H:i:s'),
        ]);

        return (int) $statement->fetchColumn();
    }

    public function hasDuplicate(
        int $targetUserId,
        int $senderTelegramUserId,
        string $contentHash,
        DateTimeImmutable $since
    ): bool {
        $statement = $this->pdo->prepare(
            'SELECT id
             FROM messages
             WHERE target_user_id = :target_user_id
               AND sender_telegram_user_id = :sender_telegram_user_id
               AND content_hash = :content_hash
               AND created_at >= :since
               AND is_deleted = 0
             LIMIT 1'
        );
        $statement->execute([
            'target_user_id' => $targetUserId,
            'sender_telegram_user_id' => $senderTelegramUserId,
            'content_hash' => $contentHash,
            'since' => $since->format('Y-m-d H:i:s'),
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function countReceivedByTarget(int $targetUserId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM messages
             WHERE target_user_id = :target_user_id
               AND is_deleted = 0'
        );
        $statement->execute(['target_user_id' => $targetUserId]);

        return (int) $statement->fetchColumn();
    }

    public function countSentBySender(int $senderTelegramUserId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM messages
             WHERE sender_telegram_user_id = :sender_telegram_user_id
               AND is_deleted = 0'
        );
        $statement->execute(['sender_telegram_user_id' => $senderTelegramUserId]);

        return (int) $statement->fetchColumn();
    }

    public function countReportsOnTarget(int $targetUserId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(r.id) AS total
             FROM reports r
             INNER JOIN messages m ON m.id = r.message_id
             WHERE m.target_user_id = :target_user_id'
        );
        $statement->execute(['target_user_id' => $targetUserId]);

        return (int) $statement->fetchColumn();
    }

    public function pruneOlderThan(DateTimeImmutable $cutoffDate): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM messages WHERE created_at < :cutoff_date'
        );
        $statement->execute(['cutoff_date' => $cutoffDate->format('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }
}

