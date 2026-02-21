<?php

declare(strict_types=1);

namespace PhpBot\Services;

use PhpBot\Config\AppConfig;
use PhpBot\Repositories\BlockRepository;
use PhpBot\Repositories\MessageRepository;
use PhpBot\Repositories\ReportRepository;
use PhpBot\Repositories\UserRepository;
use PhpBot\Security\CallbackSigner;
use PhpBot\Security\TextSanitizer;
use PhpBot\Telegram\TelegramClient;
use PhpBot\Utils\Logger;
use Throwable;

final class ReportService
{
    private ReportRepository $reportRepository;
    private MessageRepository $messageRepository;
    private BlockRepository $blockRepository;
    private UserRepository $userRepository;
    private TelegramClient $telegramClient;
    private CallbackSigner $callbackSigner;
    private AppConfig $config;
    private TextSanitizer $textSanitizer;
    private Logger $logger;

    public function __construct(
        ReportRepository $reportRepository,
        MessageRepository $messageRepository,
        BlockRepository $blockRepository,
        UserRepository $userRepository,
        TelegramClient $telegramClient,
        CallbackSigner $callbackSigner,
        AppConfig $config,
        TextSanitizer $textSanitizer,
        Logger $logger
    ) {
        $this->reportRepository = $reportRepository;
        $this->messageRepository = $messageRepository;
        $this->blockRepository = $blockRepository;
        $this->userRepository = $userRepository;
        $this->telegramClient = $telegramClient;
        $this->callbackSigner = $callbackSigner;
        $this->config = $config;
        $this->textSanitizer = $textSanitizer;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $reporterUser
     * @return array{success:bool,message:string}
     */
    public function reportMessage(array $reporterUser, int $messageId): array
    {
        $reporterUserId = (int) ($reporterUser['id'] ?? 0);
        if ($reporterUserId <= 0) {
            return ['success' => false, 'message' => 'گزارش با خطا مواجه شد.'];
        }

        $message = $this->messageRepository->findById($messageId);
        if ($message === null || (int) $message['target_user_id'] !== $reporterUserId) {
            return ['success' => false, 'message' => 'پیام گزارش‌شده پیدا نشد.'];
        }

        $reportId = $this->reportRepository->create($messageId, $reporterUserId, 'user_report');
        $this->notifyAdmins($reportId, $reporterUser, $message);

        return ['success' => true, 'message' => 'گزارش ثبت شد و برای ادمین ارسال شد.'];
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function adminBlockSender(int $adminTelegramUserId, int $reportId): array
    {
        if (!$this->config->isAdmin($adminTelegramUserId)) {
            return ['success' => false, 'message' => 'شما دسترسی ادمین ندارید.'];
        }

        $reportContext = $this->reportRepository->findWithMessageContext($reportId);
        if ($reportContext === null) {
            return ['success' => false, 'message' => 'گزارش پیدا نشد یا حذف شده است.'];
        }

        $targetUserId = (int) $reportContext['target_user_id'];
        $senderTelegramUserId = (int) $reportContext['sender_telegram_user_id'];
        if ($targetUserId <= 0 || $senderTelegramUserId <= 0) {
            return ['success' => false, 'message' => 'اطلاعات گزارش ناقص است.'];
        }

        $this->blockRepository->blockSender($targetUserId, $senderTelegramUserId);

        $reporterUser = $this->userRepository->findById((int) $reportContext['reporter_user_id']);
        if ($reporterUser !== null) {
            try {
                $this->telegramClient->sendMessage(
                    (int) $reporterUser['telegram_user_id'],
                    sprintf(
                        "✅ ادمین بر اساس گزارش #%d، فرستنده را برای شما بلاک کرد.",
                        $reportId
                    )
                );
            } catch (Throwable $throwable) {
                $this->logger->warning('Failed to notify reporter after admin block.', [
                    'report_id' => $reportId,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        return ['success' => true, 'message' => 'فرستنده برای گیرنده بلاک شد.'];
    }

    /**
     * @param array<string, mixed> $reporterUser
     * @param array<string, mixed> $message
     */
    private function notifyAdmins(int $reportId, array $reporterUser, array $message): void
    {
        $adminIds = $this->config->adminIds();
        if ($adminIds === []) {
            return;
        }

        $messageType = (string) ($message['message_type'] ?? 'text');
        $previewText = $this->textSanitizer->preview((string) ($message['text'] ?? ''), 80);
        $reportText = "🚨 گزارش جدید دریافت شد\n"
            . "• Report ID: {$reportId}\n"
            . "• Message ID: " . (int) ($message['id'] ?? 0) . "\n"
            . "• Target User ID: " . (int) ($reporterUser['id'] ?? 0) . "\n"
            . "• Target Telegram ID: " . (int) ($reporterUser['telegram_user_id'] ?? 0) . "\n"
            . "• Type: {$messageType}\n"
            . "• Preview: {$previewText}\n"
            . "• Time: " . (string) ($message['created_at'] ?? 'unknown');

        foreach ($adminIds as $adminId) {
            $keyboard = [
                'inline_keyboard' => [[
                    [
                        'text' => 'Block Sender',
                        'callback_data' => $this->callbackSigner->issue('ab', $reportId, $adminId, 604800),
                    ],
                ]],
            ];

            try {
                $this->telegramClient->sendMessage($adminId, $reportText, $keyboard);
            } catch (Throwable $throwable) {
                $this->logger->warning('Unable to notify admin about report.', [
                    'admin_id' => $adminId,
                    'report_id' => $reportId,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
    }
}

