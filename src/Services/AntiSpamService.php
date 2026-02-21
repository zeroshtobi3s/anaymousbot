<?php

declare(strict_types=1);

namespace PhpBot\Services;

use DateTimeImmutable;
use PhpBot\Config\AppConfig;
use PhpBot\Repositories\MessageRepository;

final class AntiSpamService
{
    private MessageRepository $messageRepository;
    private AppConfig $config;

    public function __construct(MessageRepository $messageRepository, AppConfig $config)
    {
        $this->messageRepository = $messageRepository;
        $this->config = $config;
    }

    public function validate(int $senderTelegramUserId, int $targetUserId, string $contentHash): ?string
    {
        $now = new DateTimeImmutable();
        $rateLimits = $this->config->rateLimits();

        $senderPerMinuteLimit = $rateLimits['sender_per_minute'] ?? 3;
        if ($senderPerMinuteLimit > 0) {
            $countPerMinute = $this->messageRepository->countBySenderSince(
                $senderTelegramUserId,
                $now->modify('-1 minute')
            );
            if ($countPerMinute >= $senderPerMinuteLimit) {
                return 'تعداد پیام‌های شما در یک دقیقه زیاد است. کمی بعد دوباره تلاش کنید.';
            }
        }

        $senderPerHourLimit = $rateLimits['sender_per_hour'] ?? 20;
        if ($senderPerHourLimit > 0) {
            $countPerHour = $this->messageRepository->countBySenderSince(
                $senderTelegramUserId,
                $now->modify('-1 hour')
            );
            if ($countPerHour >= $senderPerHourLimit) {
                return 'سقف پیام‌های ساعتی شما پر شده است. لطفاً بعداً تلاش کنید.';
            }
        }

        $targetPerMinuteLimit = $rateLimits['target_per_minute'] ?? 25;
        if ($targetPerMinuteLimit > 0) {
            $countTargetPerMinute = $this->messageRepository->countByTargetSince(
                $targetUserId,
                $now->modify('-1 minute')
            );
            if ($countTargetPerMinute >= $targetPerMinuteLimit) {
                return 'در حال حاضر دریافت پیام برای این کاربر موقتاً محدود شده است.';
            }
        }

        $duplicateWindowSeconds = $rateLimits['duplicate_window_seconds'] ?? 120;
        if ($duplicateWindowSeconds > 0) {
            $isDuplicate = $this->messageRepository->hasDuplicate(
                $targetUserId,
                $senderTelegramUserId,
                $contentHash,
                $now->modify(sprintf('-%d seconds', $duplicateWindowSeconds))
            );
            if ($isDuplicate) {
                return 'این پیام تکراری است. پیام جدید ارسال کنید.';
            }
        }

        return null;
    }
}

