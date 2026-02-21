<?php

declare(strict_types=1);

namespace PhpBot\Repositories;

use PDO;

final class BlockRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function isBlocked(int $targetUserId, int $senderTelegramUserId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id
             FROM blocks
             WHERE target_user_id = :target_user_id
               AND blocked_sender_telegram_user_id = :blocked_sender_telegram_user_id
             LIMIT 1'
        );
        $statement->execute([
            'target_user_id' => $targetUserId,
            'blocked_sender_telegram_user_id' => $senderTelegramUserId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function blockSender(int $targetUserId, int $senderTelegramUserId): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO blocks (target_user_id, blocked_sender_telegram_user_id, created_at)
             VALUES (:target_user_id, :blocked_sender_telegram_user_id, NOW())
             ON DUPLICATE KEY UPDATE target_user_id = VALUES(target_user_id)'
        );
        $statement->execute([
            'target_user_id' => $targetUserId,
            'blocked_sender_telegram_user_id' => $senderTelegramUserId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function countByTarget(int $targetUserId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM blocks
             WHERE target_user_id = :target_user_id'
        );
        $statement->execute(['target_user_id' => $targetUserId]);

        return (int) $statement->fetchColumn();
    }
}

