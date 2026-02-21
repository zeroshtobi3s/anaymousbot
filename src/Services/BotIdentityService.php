<?php

declare(strict_types=1);

namespace PhpBot\Services;

use PhpBot\Config\AppConfig;
use PhpBot\Telegram\TelegramClient;
use PhpBot\Utils\Logger;
use Throwable;

final class BotIdentityService
{
    private AppConfig $config;
    private TelegramClient $telegramClient;
    private Logger $logger;
    private ?string $cachedUsername = null;

    public function __construct(AppConfig $config, TelegramClient $telegramClient, Logger $logger)
    {
        $this->config = $config;
        $this->telegramClient = $telegramClient;
        $this->logger = $logger;
    }

    public function getBotUsername(): ?string
    {
        if ($this->cachedUsername !== null) {
            return $this->cachedUsername;
        }

        $configuredUsername = $this->config->botUsername();
        if ($configuredUsername !== null && $this->isValidUsername($configuredUsername)) {
            $this->cachedUsername = $configuredUsername;

            return $this->cachedUsername;
        }

        $cachePath = $this->config->botUsernameCachePath();
        if (is_file($cachePath)) {
            $cachedUsername = trim((string) file_get_contents($cachePath));
            if ($this->isValidUsername($cachedUsername)) {
                $this->cachedUsername = $cachedUsername;

                return $this->cachedUsername;
            }
        }

        try {
            $profile = $this->telegramClient->getMe();
            $username = trim((string) ($profile['username'] ?? ''));
            if (!$this->isValidUsername($username)) {
                return null;
            }

            $this->cachedUsername = $username;
            @file_put_contents($cachePath, $username, LOCK_EX);

            return $this->cachedUsername;
        } catch (Throwable $throwable) {
            $this->logger->warning('Unable to load bot username from Telegram API.', [
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    private function isValidUsername(string $username): bool
    {
        return preg_match('/^[A-Za-z0-9_]{5,64}$/', $username) === 1;
    }
}

