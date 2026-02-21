<?php

declare(strict_types=1);

namespace PhpBot\Bootstrap;

use PhpBot\Config\AppConfig;
use PhpBot\Controllers\UpdateController;
use PhpBot\Database\Database;
use PhpBot\Repositories\BlockRepository;
use PhpBot\Repositories\ConversationStateRepository;
use PhpBot\Repositories\MessageRepository;
use PhpBot\Repositories\ReportRepository;
use PhpBot\Repositories\UserRepository;
use PhpBot\Security\CallbackSigner;
use PhpBot\Security\TextSanitizer;
use PhpBot\Services\AnonymousMessageService;
use PhpBot\Services\AntiSpamService;
use PhpBot\Services\BotIdentityService;
use PhpBot\Services\ConversationService;
use PhpBot\Services\MaintenanceService;
use PhpBot\Services\ReportService;
use PhpBot\Services\SettingsService;
use PhpBot\Services\UserService;
use PhpBot\Telegram\TelegramClient;
use PhpBot\Utils\Logger;
use RuntimeException;

final class ApplicationFactory
{
    public static function create(string $rootPath): AppContainer
    {
        $config = AppConfig::fromEnvironment($rootPath);
        $logger = new Logger($config->logFilePath());
        $telegramClient = new TelegramClient(
            $config->botToken(),
            $logger,
            $config->telegramCaFile()
        );

        $database = new Database($config, $logger);
        $pdo = $database->getConnection();

        $userRepository = new UserRepository($pdo);
        $conversationStateRepository = new ConversationStateRepository($pdo);
        $messageRepository = new MessageRepository($pdo);
        $blockRepository = new BlockRepository($pdo);
        $reportRepository = new ReportRepository($pdo);

        $textSanitizer = new TextSanitizer();

        $signerSecret = (string) ($config->webhookSecret() ?? '');
        if ($signerSecret === '') {
            throw new RuntimeException('WEBHOOK_SECRET is required in .env for secure callback tokens.');
        }
        $callbackSigner = new CallbackSigner($signerSecret);

        $userService = new UserService($userRepository, $logger);
        $conversationService = new ConversationService($conversationStateRepository);
        $antiSpamService = new AntiSpamService($messageRepository, $config);
        $settingsService = new SettingsService($userService, $textSanitizer, $callbackSigner);
        $anonymousMessageService = new AnonymousMessageService(
            $messageRepository,
            $blockRepository,
            $telegramClient,
            $callbackSigner,
            $config,
            $antiSpamService,
            $textSanitizer,
            $userService,
            $logger
        );
        $reportService = new ReportService(
            $reportRepository,
            $messageRepository,
            $blockRepository,
            $userRepository,
            $telegramClient,
            $callbackSigner,
            $config,
            $textSanitizer,
            $logger
        );
        $botIdentityService = new BotIdentityService($config, $telegramClient, $logger);
        $maintenanceService = new MaintenanceService(
            $messageRepository,
            $config,
            $logger,
            $config->maintenanceFlagPath()
        );

        $updateController = new UpdateController(
            $config,
            $logger,
            $telegramClient,
            $userService,
            $settingsService,
            $conversationService,
            $anonymousMessageService,
            $reportService,
            $botIdentityService,
            $messageRepository,
            $blockRepository,
            $reportRepository,
            $callbackSigner,
            $textSanitizer,
            $maintenanceService
        );

        return new AppContainer($config, $logger, $telegramClient, $updateController);
    }
}

