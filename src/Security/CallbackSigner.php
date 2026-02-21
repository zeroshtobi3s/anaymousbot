<?php

declare(strict_types=1);

namespace PhpBot\Security;

use RuntimeException;

final class CallbackSigner
{
    private string $secret;

    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw new RuntimeException('WEBHOOK_SECRET is required for callback signing.');
        }

        $this->secret = $secret;
    }

    public function issue(string $action, int $referenceId, int $telegramUserId, int $ttlSeconds = 86400): string
    {
        $expiresAt = time() + max(60, $ttlSeconds);
        $payload = sprintf('%s|%d|%d|%d', $action, $referenceId, $telegramUserId, $expiresAt);
        $signature = substr(hash_hmac('sha256', $payload, $this->secret), 0, 16);

        return sprintf('%s.%d.%d.%d.%s', $action, $referenceId, $telegramUserId, $expiresAt, $signature);
    }

    /**
     * @return array{action:string, reference_id:int, telegram_user_id:int, expires_at:int}|null
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 5) {
            return null;
        }

        [$action, $referenceIdRaw, $telegramUserIdRaw, $expiresAtRaw, $signature] = $parts;
        if (preg_match('/^[a-z]{1,3}$/', $action) !== 1) {
            return null;
        }
        if (!ctype_digit($referenceIdRaw) || !ctype_digit($telegramUserIdRaw) || !ctype_digit($expiresAtRaw)) {
            return null;
        }
        if (strlen($signature) !== 16 || preg_match('/^[a-f0-9]+$/', $signature) !== 1) {
            return null;
        }

        $referenceId = (int) $referenceIdRaw;
        $telegramUserId = (int) $telegramUserIdRaw;
        $expiresAt = (int) $expiresAtRaw;
        if ($referenceId < 0 || $telegramUserId <= 0 || $expiresAt < time()) {
            return null;
        }

        $payload = sprintf('%s|%d|%d|%d', $action, $referenceId, $telegramUserId, $expiresAt);
        $expectedSignature = substr(hash_hmac('sha256', $payload, $this->secret), 0, 16);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return [
            'action' => $action,
            'reference_id' => $referenceId,
            'telegram_user_id' => $telegramUserId,
            'expires_at' => $expiresAt,
        ];
    }
}

