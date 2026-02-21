<?php

declare(strict_types=1);

namespace PhpBot\Repositories;

use PDO;

final class ConversationStateRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveState(int $telegramUserId, string $stateName, array $payload): void
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            $payloadJson = '{}';
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_states (telegram_user_id, state_name, payload_json, updated_at)
             VALUES (:telegram_user_id, :state_name, :payload_json, NOW())
             ON DUPLICATE KEY UPDATE
                state_name = VALUES(state_name),
                payload_json = VALUES(payload_json),
                updated_at = NOW()'
        );
        $statement->execute([
            'telegram_user_id' => $telegramUserId,
            'state_name' => $stateName,
            'payload_json' => $payloadJson,
        ]);
    }

    /**
     * @return array{state_name:string,payload:array<string,mixed>,updated_at:string}|null
     */
    public function findByTelegramUserId(int $telegramUserId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT state_name, payload_json, updated_at
             FROM conversation_states
             WHERE telegram_user_id = :telegram_user_id
             LIMIT 1'
        );
        $statement->execute(['telegram_user_id' => $telegramUserId]);
        $result = $statement->fetch();
        if (!is_array($result)) {
            return null;
        }

        $payload = json_decode((string) $result['payload_json'], true);
        if (!is_array($payload)) {
            $payload = [];
        }

        return [
            'state_name' => (string) $result['state_name'],
            'payload' => $payload,
            'updated_at' => (string) $result['updated_at'],
        ];
    }

    public function clearState(int $telegramUserId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM conversation_states WHERE telegram_user_id = :telegram_user_id'
        );
        $statement->execute(['telegram_user_id' => $telegramUserId]);
    }
}

