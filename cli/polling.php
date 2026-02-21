<?php

declare(strict_types=1);

use PhpBot\Bootstrap\ApplicationFactory;

if (PHP_SAPI !== 'cli') {
    echo "This script must run in CLI mode.\n";
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$app = ApplicationFactory::create(dirname(__DIR__));
$config = $app->config;
$logger = $app->logger;

if (!$config->isPollingMode()) {
    $logger->warning('Polling started while APP_MODE is not polling.', [
        'app_mode' => $config->appMode(),
    ]);
}

$offsetFilePath = $config->pollingOffsetPath();
$offset = 0;
if (is_file($offsetFilePath)) {
    $savedOffset = trim((string) file_get_contents($offsetFilePath));
    if ($savedOffset !== '' && ctype_digit($savedOffset)) {
        $offset = (int) $savedOffset;
    }
}

$logger->info('Polling worker started.', [
    'offset' => $offset,
    'timeout' => $config->pollingTimeout(),
]);

while (true) {
    try {
        $updates = $app->telegramClient->getUpdates($offset, $config->pollingTimeout());
        if ($updates === []) {
            sleep($config->pollingIdleSleep());
            continue;
        }

        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }

            $updateId = (int) ($update['update_id'] ?? 0);
            if ($updateId <= 0) {
                continue;
            }

            $app->updateController->handleUpdate($update);
            $offset = $updateId + 1;
        }

        @file_put_contents($offsetFilePath, (string) $offset, LOCK_EX);
    } catch (Throwable $throwable) {
        $logger->warning('Polling iteration failed.', [
            'error' => $throwable->getMessage(),
            'offset' => $offset,
        ]);
        sleep(max(2, $config->pollingIdleSleep()));
    }
}

