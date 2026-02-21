<?php

declare(strict_types=1);

namespace PhpBot\Services;

use DateTimeImmutable;
use PhpBot\Config\AppConfig;
use PhpBot\Repositories\MessageRepository;
use PhpBot\Utils\Logger;
use Throwable;

final class MaintenanceService
{
    private MessageRepository $messageRepository;
    private AppConfig $config;
    private Logger $logger;
    private string $flagPath;

    public function __construct(
        MessageRepository $messageRepository,
        AppConfig $config,
        Logger $logger,
        string $flagPath
    ) {
        $this->messageRepository = $messageRepository;
        $this->config = $config;
        $this->logger = $logger;
        $this->flagPath = $flagPath;
    }

    public function runIfDue(): void
    {
        $retentionDays = $this->config->messageRetentionDays();
        if ($retentionDays <= 0) {
            return;
        }

        $currentTimestamp = time();
        $lastRun = @filemtime($this->flagPath);
        if ($lastRun !== false && ($currentTimestamp - $lastRun) < 21600) {
            return;
        }

        try {
            $cutoffDate = new DateTimeImmutable(sprintf('-%d days', $retentionDays));
            $deletedCount = $this->messageRepository->pruneOlderThan($cutoffDate);
            $this->logger->info('Message retention maintenance executed.', [
                'retention_days' => $retentionDays,
                'deleted_messages' => $deletedCount,
            ]);
        } catch (Throwable $throwable) {
            $this->logger->warning('Message retention maintenance failed.', [
                'error' => $throwable->getMessage(),
            ]);
        } finally {
            @touch($this->flagPath);
        }
    }
}

