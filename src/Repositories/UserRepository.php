<?php

declare(strict_types=1);

namespace PhpBot\Repositories;

use PDO;

final class UserRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTelegramUserId(int $telegramUserId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, telegram_user_id, first_name, username, public_slug, is_active, settings_json, created_at, updated_at
             FROM users
             WHERE telegram_user_id = :telegram_user_id
             LIMIT 1'
        );
        $statement->execute(['telegram_user_id' => $telegramUserId]);

        $result = $statement->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicSlug(string $publicSlug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, telegram_user_id, first_name, username, public_slug, is_active, settings_json, created_at, updated_at
             FROM users
             WHERE public_slug = :public_slug
             LIMIT 1'
        );
        $statement->execute(['public_slug' => $publicSlug]);

        $result = $statement->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, telegram_user_id, first_name, username, public_slug, is_active, settings_json, created_at, updated_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        $result = $statement->fetch();

        return is_array($result) ? $result : null;
    }

    public function create(
        int $telegramUserId,
        string $firstName,
        ?string $username,
        string $publicSlug,
        string $settingsJson
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (telegram_user_id, first_name, username, public_slug, is_active, settings_json, created_at, updated_at)
             VALUES (:telegram_user_id, :first_name, :username, :public_slug, 1, :settings_json, NOW(), NOW())'
        );
        $statement->execute([
            'telegram_user_id' => $telegramUserId,
            'first_name' => $firstName,
            'username' => $username,
            'public_slug' => $publicSlug,
            'settings_json' => $settingsJson,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateProfile(int $telegramUserId, string $firstName, ?string $username): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET first_name = :first_name, username = :username, updated_at = NOW()
             WHERE telegram_user_id = :telegram_user_id'
        );
        $statement->execute([
            'first_name' => $firstName,
            'username' => $username,
            'telegram_user_id' => $telegramUserId,
        ]);
    }

    public function updateSettingsJson(int $userId, string $settingsJson): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET settings_json = :settings_json, updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'settings_json' => $settingsJson,
            'id' => $userId,
        ]);
    }

    public function setActive(int $userId, bool $isActive): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET is_active = :is_active, updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'is_active' => $isActive ? 1 : 0,
            'id' => $userId,
        ]);
    }
}

