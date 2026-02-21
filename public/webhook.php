<?php

declare(strict_types=1);

use PhpBot\Bootstrap\ApplicationFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

$app = null;
try {
    $app = ApplicationFactory::create(dirname(__DIR__));

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $secret = (string) ($app->config->webhookSecret() ?? '');
    if ($secret !== '') {
        $headerSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        $querySecret = trim((string) ($_GET['secret'] ?? ''));
        $isValidSecret = false;

        if ($headerSecret !== '') {
            $isValidSecret = hash_equals($secret, $headerSecret);
        }
        if (!$isValidSecret && $querySecret !== '') {
            $isValidSecret = hash_equals($secret, $querySecret);
        }

        if (!$isValidSecret) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $rawBody = file_get_contents('php://input');
    $update = json_decode((string) $rawBody, true);
    if (!is_array($update)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid update payload'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $app->updateController->handleUpdate($update);
    http_response_code(200);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $throwable) {
    if ($app !== null) {
        $app->logger->error('Webhook fatal error.', [
            'error' => $throwable->getMessage(),
        ]);
    }

    http_response_code(200);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}

