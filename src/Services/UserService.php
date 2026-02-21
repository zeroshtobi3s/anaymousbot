<?php

declare(strict_types=1);

namespace PhpBot\Services;

use PhpBot\Repositories\UserRepository;
use PhpBot\Utils\Logger;
use RuntimeException;

final class UserService
{
    /**
     * @var array<string, mixed>
     */
    private const DEFAULT_SETTINGS = [
        'accept_messages' => true,
        'allow_media' => true,
        'banned_words' => [],
    ];

    private UserRepository $userRepository;
    private Logger $logger;

    public function __construct(UserRepository $userRepository, Logger $logger)
    {
        $this->userRepository = $userRepository;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $telegramUser
     * @return array<string, mixed>
     */
    public function ensureUser(array $telegramUser): array
    {
        $telegramUserId = (int) ($telegramUser['id'] ?? 0);
        if ($telegramUserId <= 0) {
            throw new RuntimeException('Telegram user id is missing.');
        }

        $firstName = trim((string) ($telegramUser['first_name'] ?? 'کاربر'));
        if ($firstName === '') {
            $firstName = 'کاربر';
        }
        $usernameRaw = trim((string) ($telegramUser['username'] ?? ''));
        $username = $usernameRaw === '' ? null : $usernameRaw;

        $existingUser = $this->userRepository->findByTelegramUserId($telegramUserId);
        if ($existingUser === null) {
            $publicSlug = $this->generateUniqueSlug();
            $settingsJson = json_encode(self::DEFAULT_SETTINGS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($settingsJson === false) {
                $settingsJson = '{"accept_messages":true,"allow_media":true,"banned_words":[]}';
            }

            $userId = $this->userRepository->create(
                $telegramUserId,
                $firstName,
                $username,
                $publicSlug,
                $settingsJson
            );
            $createdUser = $this->userRepository->findById($userId);
            if ($createdUser === null) {
                throw new RuntimeException('New user creation failed.');
            }

            $this->logger->info('User registered.', [
                'user_id' => $userId,
                'telegram_user_id' => $telegramUserId,
            ]);

            return $createdUser;
        }

        $this->userRepository->updateProfile($telegramUserId, $firstName, $username);

        $normalizedSettings = $this->normalizeSettings($this->getSettings($existingUser));
        $encodedSettings = json_encode($normalizedSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedSettings !== false && $encodedSettings !== (string) ($existingUser['settings_json'] ?? '')) {
            $this->userRepository->updateSettingsJson((int) $existingUser['id'], $encodedSettings);
            $existingUser['settings_json'] = $encodedSettings;
        }

        $existingUser['first_name'] = $firstName;
        $existingUser['username'] = $username;

        return $existingUser;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTelegramUserId(int $telegramUserId): ?array
    {
        return $this->userRepository->findByTelegramUserId($telegramUserId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $cleanSlug = trim(strtolower($slug));
        if (preg_match('/^u_[a-z0-9]{6,20}$/', $cleanSlug) !== 1) {
            return null;
        }

        return $this->userRepository->findByPublicSlug($cleanSlug);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $userId): ?array
    {
        return $this->userRepository->findById($userId);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getSettings(array $user): array
    {
        $decodedSettings = json_decode((string) ($user['settings_json'] ?? ''), true);
        if (!is_array($decodedSettings)) {
            $decodedSettings = [];
        }

        return $this->normalizeSettings($decodedSettings);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function updateSettings(int $userId, array $settings): void
    {
        $normalizedSettings = $this->normalizeSettings($settings);
        $settingsJson = json_encode($normalizedSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($settingsJson === false) {
            return;
        }

        $this->userRepository->updateSettingsJson($userId, $settingsJson);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function getDisplayName(array $user): string
    {
        $username = trim((string) ($user['username'] ?? ''));
        if ($username !== '') {
            return '@' . $username;
        }

        $firstName = trim((string) ($user['first_name'] ?? 'کاربر'));

        return $firstName === '' ? 'کاربر' : $firstName;
    }

    private function generateUniqueSlug(): string
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $slug = 'u_' . substr(bin2hex(random_bytes(5)), 0, 6);
            if ($this->userRepository->findByPublicSlug($slug) === null) {
                return $slug;
            }
        }

        throw new RuntimeException('Unable to generate unique slug.');
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $settings): array
    {
        $acceptMessages = $settings['accept_messages'] ?? self::DEFAULT_SETTINGS['accept_messages'];
        $allowMedia = $settings['allow_media'] ?? self::DEFAULT_SETTINGS['allow_media'];
        $bannedWordsRaw = $settings['banned_words'] ?? self::DEFAULT_SETTINGS['banned_words'];

        $bannedWords = [];
        if (is_array($bannedWordsRaw)) {
            foreach ($bannedWordsRaw as $word) {
                $word = trim((string) $word);
                if ($word === '' || $this->stringLength($word) > 32) {
                    continue;
                }
                $bannedWords[] = $word;
            }
        }

        return [
            'accept_messages' => (bool) $acceptMessages,
            'allow_media' => (bool) $allowMedia,
            'banned_words' => array_values(array_unique($bannedWords)),
        ];
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }
}
