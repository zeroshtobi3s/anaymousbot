<?php

declare(strict_types=1);

namespace PhpBot\Repositories;

use PDO;

final class ReportRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(int $messageId, int $reporterUserId, string $reason): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO reports (message_id, reporter_user_id, reason, created_at)
             VALUES (:message_id, :reporter_user_id, :reason, NOW())'
        );
        $statement->execute([
            'message_id' => $messageId,
            'reporter_user_id' => $reporterUserId,
            'reason' => $reason,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function countByReporter(int $reporterUserId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM reports
             WHERE reporter_user_id = :reporter_user_id'
        );
        $statement->execute(['reporter_user_id' => $reporterUserId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWithMessageContext(int $reportId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                r.id AS report_id,
                r.message_id,
                r.reporter_user_id,
                r.reason,
                r.created_at AS report_created_at,
                m.target_user_id,
                m.sender_telegram_user_id,
                m.message_type,
                m.text,
                m.media_file_id,
                m.created_at AS message_created_at
             FROM reports r
             INNER JOIN messages m ON m.id = r.message_id
             WHERE r.id = :report_id
             LIMIT 1'
        );
        $statement->execute(['report_id' => $reportId]);
        $result = $statement->fetch();

        return is_array($result) ? $result : null;
    }
}

