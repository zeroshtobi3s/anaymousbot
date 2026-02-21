<?php

declare(strict_types=1);

namespace PhpBot\Config;

use Dotenv\Dotenv;
use RuntimeException;

final class AppConfig
{
    private const DEFAULT_RATE_LIMITS = [
        'sender_per_minute' => 3,
        'sender_per_hour' => 20,
        'target_per_minute' => 25,
        'duplicate_window_seconds' => 120,
    ];

    private string $rootPath;
    private string $botToken;
    private ?string $botUsername;
    private string $appMode;
    private ?string $appBaseUrl;
    private ?string $webhookSecret;
    private ?string $telegramCaFile;
    private bool $requireChannelJoin;
    private ?string $requiredChannelUsername;
    private string $dbHost;
    private int $dbPort;
    private string $dbName;
    private string $dbUser;
    private string $dbPass;
    private string $dbCharset;
    /** @var int[] */
    private array $adminIds;
    private int $messageRetentionDays;
    /** @var array<string, int> */
    private array $rateLimits;
    private int $maxTextLength;
    private int $maxCaptionLength;
    private int $maxPhotoSizeBytes;
    private int $pollingTimeout;
    private int $pollingIdleSleep;

    private function __construct()
    {
    }

    public static function fromEnvironment(string $rootPath): self
    {
        if (is_file($rootPath . DIRECTORY_SEPARATOR . '.env')) {
            Dotenv::createImmutable($rootPath)->safeLoad();
        }

        $self = new self();
        $self->rootPath = $rootPath;
        $self->botToken = self::requiredEnv('BOT_TOKEN');
        $self->botUsername = self::nullableEnv('BOT_USERNAME');
        $self->appMode = self::normalizeMode(self::env('APP_MODE', 'polling'));
        $self->appBaseUrl = self::normalizeBaseUrl(self::nullableEnv('APP_BASE_URL'));
        $self->webhookSecret = self::nullableEnv('WEBHOOK_SECRET');
        $self->telegramCaFile = self::resolveNullablePath(
            $rootPath,
            self::nullableEnv('TELEGRAM_CA_FILE')
        );
        $self->requireChannelJoin = self::toBool(self::env('REQUIRE_CHANNEL_JOIN', '1'));
        $self->requiredChannelUsername = self::normalizeChannelUsername(
            self::env('REQUIRED_CHANNEL_USERNAME', '@rceold')
        );

        $self->dbHost = self::env('DB_HOST', '127.0.0.1');
        $self->dbPort = self::toInt(self::env('DB_PORT', '3306'), 3306, 1, 65535);
        $self->dbName = self::requiredEnv('DB_NAME');
        $self->dbUser = self::requiredEnv('DB_USER');
        $self->dbPass = self::env('DB_PASS', '');
        $self->dbCharset = self::env('DB_CHARSET', 'utf8mb4');

        $self->adminIds = self::parseAdminIds(self::env('ADMIN_IDS', ''));
        $self->messageRetentionDays = self::toInt(
            self::env('MESSAGE_RETENTION_DAYS', '90'),
            90,
            1,
            3650
        );
        $self->rateLimits = self::parseRateLimits(self::env('RATE_LIMITS', ''));
        $self->maxTextLength = self::toInt(self::env('MAX_TEXT_LENGTH', '2000'), 2000, 100, 4096);
        $self->maxCaptionLength = self::toInt(
            self::env('MAX_CAPTION_LENGTH', '900'),
            900,
            50,
            1024
        );
        $self->maxPhotoSizeBytes = self::toInt(self::env('MAX_PHOTO_SIZE_MB', '10'), 10, 1, 20) * 1024 * 1024;
        $self->pollingTimeout = self::toInt(self::env('POLLING_TIMEOUT', '25'), 25, 1, 50);
        $self->pollingIdleSleep = self::toInt(self::env('POLLING_IDLE_SLEEP', '1'), 1, 1, 10);

        return $self;
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function botToken(): string
    {
        return $this->botToken;
    }

    public function botUsername(): ?string
    {
        return $this->botUsername;
    }

    public function appMode(): string
    {
        return $this->appMode;
    }

    public function isWebhookMode(): bool
    {
        return $this->appMode === 'webhook';
    }

    public function isPollingMode(): bool
    {
        return $this->appMode === 'polling';
    }

    public function appBaseUrl(): ?string
    {
        return $this->appBaseUrl;
    }

    public function webhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    public function telegramCaFile(): ?string
    {
        return $this->telegramCaFile;
    }

    public function requireChannelJoin(): bool
    {
        return $this->requireChannelJoin && $this->requiredChannelUsername !== null;
    }

    public function requiredChannelUsername(): ?string
    {
        return $this->requiredChannelUsername;
    }

    public function requiredChannelUrl(): ?string
    {
        if ($this->requiredChannelUsername === null) {
            return null;
        }

        return 'https://t.me/' . ltrim($this->requiredChannelUsername, '@');
    }

    public function dbHost(): string
    {
        return $this->dbHost;
    }

    public function dbPort(): int
    {
        return $this->dbPort;
    }

    public function dbName(): string
    {
        return $this->dbName;
    }

    public function dbUser(): string
    {
        return $this->dbUser;
    }

    public function dbPass(): string
    {
        return $this->dbPass;
    }

    public function dbCharset(): string
    {
        return $this->dbCharset;
    }

    /**
     * @return int[]
     */
    public function adminIds(): array
    {
        return $this->adminIds;
    }

    public function isAdmin(int $telegramUserId): bool
    {
        return in_array($telegramUserId, $this->adminIds, true);
    }

    public function messageRetentionDays(): int
    {
        return $this->messageRetentionDays;
    }

    /**
     * @return array<string, int>
     */
    public function rateLimits(): array
    {
        return $this->rateLimits;
    }

    public function maxTextLength(): int
    {
        return $this->maxTextLength;
    }

    public function maxCaptionLength(): int
    {
        return $this->maxCaptionLength;
    }

    public function maxPhotoSizeBytes(): int
    {
        return $this->maxPhotoSizeBytes;
    }

    public function pollingTimeout(): int
    {
        return $this->pollingTimeout;
    }

    public function pollingIdleSleep(): int
    {
        return $this->pollingIdleSleep;
    }

    public function dbDsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->dbHost,
            $this->dbPort,
            $this->dbName,
            $this->dbCharset
        );
    }

    public function logFilePath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
    }

    public function maintenanceFlagPath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'maintenance.flag';
    }

    public function pollingOffsetPath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'polling.offset';
    }

    public function botUsernameCachePath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'bot_username.cache';
    }

    private static function requiredEnv(string $key): string
    {
        $value = self::env($key);
        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('%s is required in .env', $key));
        }

        return $value;
    }

    private static function nullableEnv(string $key): ?string
    {
        $value = self::env($key);
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }

    private static function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return trim((string) $value);
    }

    private static function normalizeMode(?string $value): string
    {
        $mode = strtolower((string) $value);
        if (!in_array($mode, ['webhook', 'polling'], true)) {
            return 'polling';
        }

        return $mode;
    }

    private static function normalizeBaseUrl(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return rtrim($value, '/');
    }

    private static function resolveNullablePath(string $rootPath, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (self::isAbsolutePath($value)) {
            return $value;
        }

        return $rootPath . DIRECTORY_SEPARATOR . ltrim($value, '/\\');
    }

    private static function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1;
    }

    private static function toInt(string $value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $result = (int) $value;
        if ($result < $min) {
            return $min;
        }
        if ($result > $max) {
            return $max;
        }

        return $result;
    }

    private static function toBool(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private static function normalizeChannelUsername(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'https://t.me/')) {
            $trimmed = substr($trimmed, strlen('https://t.me/'));
        } elseif (str_starts_with($trimmed, 'http://t.me/')) {
            $trimmed = substr($trimmed, strlen('http://t.me/'));
        }

        $trimmed = trim($trimmed, "/ \t\n\r\0\x0B");
        $trimmed = ltrim($trimmed, '@');

        if ($trimmed === '' || preg_match('/^[a-zA-Z0-9_]{5,64}$/', $trimmed) !== 1) {
            return null;
        }

        return '@' . $trimmed;
    }

    /**
     * @return int[]
     */
    private static function parseAdminIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $result = [];
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        foreach ($parts as $part) {
            if ($part === '' || !preg_match('/^-?\d+$/', $part)) {
                continue;
            }
            $result[] = (int) $part;
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array<string, int>
     */
    private static function parseRateLimits(string $raw): array
    {
        $limits = self::DEFAULT_RATE_LIMITS;
        if ($raw === '') {
            return $limits;
        }

        $segments = preg_split('/[;,]/', $raw) ?: [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $separator = str_contains($segment, '=') ? '=' : ':';
            if (!str_contains($segment, $separator)) {
                continue;
            }

            [$key, $value] = array_map('trim', explode($separator, $segment, 2));
            if ($key === '' || $value === '' || !array_key_exists($key, $limits)) {
                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            $limits[$key] = max(1, (int) $value);
        }

        return $limits;
    }
}
