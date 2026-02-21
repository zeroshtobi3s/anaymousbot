<?php

declare(strict_types=1);

namespace PhpBot\Bootstrap;

use PhpBot\Config\AppConfig;
use PhpBot\Controllers\UpdateController;
use PhpBot\Telegram\TelegramClient;
use PhpBot\Utils\Logger;

final class AppContainer
{
    public AppConfig $config;
    public Logger $logger;
    public TelegramClient $telegramClient;
    public UpdateController $updateController;

    public function __construct(
        AppConfig $config,
        Logger $logger,
        TelegramClient $telegramClient,
        UpdateController $updateController
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->telegramClient = $telegramClient;
        $this->updateController = $updateController;
    }
}

