<?php

declare(strict_types=1);

namespace PhpBot\Services;

use PhpBot\Config\AppConfig;
use PhpBot\Repositories\BlockRepository;
use PhpBot\Repositories\MessageRepository;
use PhpBot\Security\CallbackSigner;
use PhpBot\Security\TextSanitizer;
use PhpBot\Telegram\TelegramClient;
use PhpBot\Utils\Logger;
use Throwable;

final class AnonymousMessageService
{
    private const ACTION_BUTTON_TTL_SECONDS = 2592000;

    private MessageRepository $messageRepository;
    private BlockRepository $blockRepository;
    private TelegramClient $telegramClient;
    private CallbackSigner $callbackSigner;
    private AppConfig $config;
    private AntiSpamService $antiSpamService;
    private TextSanitizer $textSanitizer;
    private UserService $userService;
    private Logger $logger;

    public function __construct(
        MessageRepository $messageRepository,
        BlockRepository $blockRepository,
        TelegramClient $telegramClient,
        CallbackSigner $callbackSigner,
        AppConfig $config,
        AntiSpamService $antiSpamService,
        TextSanitizer $textSanitizer,
        UserService $userService,
        Logger $logger
    ) {
        $this->messageRepository = $messageRepository;
        $this->blockRepository = $blockRepository;
        $this->telegramClient = $telegramClient;
        $this->callbackSigner = $callbackSigner;
        $this->config = $config;
        $this->antiSpamService = $antiSpamService;
        $this->textSanitizer = $textSanitizer;
        $this->userService = $userService;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $senderUser
     * @param array<string, mixed> $targetUser
     * @param array<string, mixed> $payload
     * @return array{success:bool,message:string,message_id?:int}
     */
    public function submitAnonymousMessage(array $senderUser, array $targetUser, array $payload): array
    {
        if ((int) ($targetUser['is_active'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'این کاربر در حال حاضر غیرفعال است.'];
        }

        $targetUserId = (int) $targetUser['id'];
        $targetTelegramUserId = (int) $targetUser['telegram_user_id'];
        $senderTelegramUserId = (int) $senderUser['telegram_user_id'];
        if ($targetUserId <= 0 || $targetTelegramUserId <= 0 || $senderTelegramUserId <= 0) {
            return ['success' => false, 'message' => 'اطلاعات کاربری کامل نیست.'];
        }

        if ($this->blockRepository->isBlocked($targetUserId, $senderTelegramUserId)) {
            return ['success' => false, 'message' => 'شما توسط این کاربر بلاک شده‌اید.'];
        }

        $targetSettings = $this->userService->getSettings($targetUser);
        if (!((bool) ($targetSettings['accept_messages'] ?? true))) {
            return ['success' => false, 'message' => 'این کاربر دریافت پیام را غیرفعال کرده است.'];
        }

        $messageType = (string) ($payload['type'] ?? '');
        $text = $this->textSanitizer->sanitizeIncomingText((string) ($payload['text'] ?? ''));
        $mediaFileId = trim((string) ($payload['media_file_id'] ?? ''));
        $photoSize = (int) ($payload['photo_size'] ?? 0);

        if ($messageType === 'text') {
            if ($text === '') {
                return ['success' => false, 'message' => 'متن پیام خالی است.'];
            }
            if ($this->textLength($text) > $this->config->maxTextLength()) {
                return [
                    'success' => false,
                    'message' => sprintf('حداکثر طول متن %d کاراکتر است.', $this->config->maxTextLength()),
                ];
            }
        } elseif ($messageType === 'photo') {
            if ($mediaFileId === '') {
                return ['success' => false, 'message' => 'عکس معتبر نیست.'];
            }
            if (!((bool) ($targetSettings['allow_media'] ?? true))) {
                return ['success' => false, 'message' => 'این کاربر فقط پیام متنی می‌پذیرد.'];
            }
            if ($photoSize > $this->config->maxPhotoSizeBytes()) {
                return ['success' => false, 'message' => 'حجم عکس زیاد است. لطفاً عکس کوچک‌تری بفرستید.'];
            }
            if ($text !== '' && $this->textLength($text) > $this->config->maxCaptionLength()) {
                return [
                    'success' => false,
                    'message' => sprintf('حداکثر طول کپشن %d کاراکتر است.', $this->config->maxCaptionLength()),
                ];
            }
        } else {
            return ['success' => false, 'message' => 'فقط پیام متنی یا عکس پشتیبانی می‌شود.'];
        }

        $bannedWords = $targetSettings['banned_words'] ?? [];
        if ($text !== '' && is_array($bannedWords) && $this->textSanitizer->containsForbiddenWords($text, $bannedWords)) {
            return ['success' => false, 'message' => 'پیام شما شامل کلمات غیرمجاز برای این کاربر است.'];
        }

        $contentHash = $this->textSanitizer->makeContentHash($text, $mediaFileId);
        $rateLimitError = $this->antiSpamService->validate($senderTelegramUserId, $targetUserId, $contentHash);
        if ($rateLimitError !== null) {
            return ['success' => false, 'message' => $rateLimitError];
        }

        $savedMessageId = $this->messageRepository->create(
            $targetUserId,
            $senderTelegramUserId,
            $this->createThreadId(),
            $messageType,
            $text !== '' ? $text : null,
            $mediaFileId !== '' ? $mediaFileId : null,
            $contentHash
        );

        $keyboard = $this->buildMessageActionKeyboard($savedMessageId, $targetTelegramUserId);
        try {
            if ($messageType === 'photo') {
                $caption = "📩 پیام ناشناس جدید (عکس)";
                if ($text !== '') {
                    $caption .= "\n\n" . $text;
                }
                $caption .= "\n\n↩️ برای پاسخ، روی همین پیام Reply کنید یا دکمه پاسخ را بزنید.";
                $this->telegramClient->sendPhoto($targetTelegramUserId, $mediaFileId, $caption, $keyboard);
            } else {
                $this->telegramClient->sendMessage(
                    $targetTelegramUserId,
                    "📩 پیام ناشناس جدید:\n\n" . $text
                    . "\n\n↩️ برای پاسخ، روی همین پیام Reply کنید یا دکمه پاسخ را بزنید.",
                    $keyboard
                );
            }
        } catch (Throwable $throwable) {
            $this->logger->warning('Anonymous message saved but target delivery failed.', [
                'message_id' => $savedMessageId,
                'target_telegram_user_id' => $targetTelegramUserId,
                'error' => $throwable->getMessage(),
            ]);

            return [
                'success' => true,
                'message' => 'پیام ثبت شد. تحویل ممکن است با کمی تأخیر انجام شود.',
                'message_id' => $savedMessageId,
            ];
        }

        return [
            'success' => true,
            'message' => 'پیام ناشناس با موفقیت ارسال شد.',
            'message_id' => $savedMessageId,
        ];
    }

    /**
     * @param array<string, mixed> $fromUser
     * @return array{success:bool,message:string}
     */
    public function sendAnonymousReply(array $fromUser, int $messageId, string $replyText): array
    {
        $fromUserId = (int) ($fromUser['id'] ?? 0);
        $fromTelegramUserId = (int) ($fromUser['telegram_user_id'] ?? 0);
        if ($fromUserId <= 0 || $fromTelegramUserId <= 0) {
            return ['success' => false, 'message' => 'کاربر نامعتبر است.'];
        }

        $cleanReplyText = $this->textSanitizer->sanitizeIncomingText($replyText);
        if ($cleanReplyText === '') {
            return ['success' => false, 'message' => 'متن پاسخ خالی است.'];
        }
        if ($this->textLength($cleanReplyText) > $this->config->maxTextLength()) {
            return [
                'success' => false,
                'message' => sprintf('حداکثر طول پاسخ %d کاراکتر است.', $this->config->maxTextLength()),
            ];
        }

        $baseMessage = $this->messageRepository->findById($messageId);
        if ($baseMessage === null || (int) $baseMessage['target_user_id'] !== $fromUserId) {
            return ['success' => false, 'message' => 'پیام مبدا پیدا نشد یا متعلق به شما نیست.'];
        }

        $targetTelegramUserId = (int) $baseMessage['sender_telegram_user_id'];
        if ($targetTelegramUserId <= 0) {
            return ['success' => false, 'message' => 'ارسال‌کننده معتبر نیست.'];
        }

        $targetUser = $this->userService->findByTelegramUserId($targetTelegramUserId);
        if ($targetUser === null) {
            return ['success' => false, 'message' => 'کاربر مقابل پیدا نشد.'];
        }
        if ((int) ($targetUser['is_active'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'کاربر مقابل در حال حاضر غیرفعال است.'];
        }

        $targetUserId = (int) ($targetUser['id'] ?? 0);
        if ($targetUserId <= 0) {
            return ['success' => false, 'message' => 'اطلاعات کاربر مقابل ناقص است.'];
        }

        if ($this->blockRepository->isBlocked($targetUserId, $fromTelegramUserId)) {
            return ['success' => false, 'message' => 'شما توسط کاربر مقابل بلاک شده‌اید.'];
        }

        $targetSettings = $this->userService->getSettings($targetUser);
        if (!((bool) ($targetSettings['accept_messages'] ?? true))) {
            return ['success' => false, 'message' => 'کاربر مقابل دریافت پیام را غیرفعال کرده است.'];
        }

        $bannedWords = $targetSettings['banned_words'] ?? [];
        if (is_array($bannedWords) && $this->textSanitizer->containsForbiddenWords($cleanReplyText, $bannedWords)) {
            return ['success' => false, 'message' => 'پاسخ شما شامل کلمات غیرمجاز برای این کاربر است.'];
        }

        $contentHash = $this->textSanitizer->makeContentHash($cleanReplyText, null);
        $rateLimitError = $this->antiSpamService->validate($fromTelegramUserId, $targetUserId, $contentHash);
        if ($rateLimitError !== null) {
            return ['success' => false, 'message' => $rateLimitError];
        }

        $savedMessageId = $this->messageRepository->create(
            $targetUserId,
            $fromTelegramUserId,
            $this->createThreadId(),
            'text',
            $cleanReplyText,
            null,
            $contentHash
        );

        $keyboard = $this->buildMessageActionKeyboard($savedMessageId, $targetTelegramUserId);
        try {
            $this->telegramClient->sendMessage(
                $targetTelegramUserId,
                "💬 پاسخ ناشناس دریافت کردی:\n\n" . $cleanReplyText
                . "\n\n↩️ برای ادامه گفتگو، روی همین پیام Reply کنید یا دکمه پاسخ را بزنید.",
                $keyboard
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Anonymous reply saved but target delivery failed.', [
                'message_id' => $savedMessageId,
                'target_telegram_user_id' => $targetTelegramUserId,
                'error' => $throwable->getMessage(),
            ]);

            return ['success' => true, 'message' => 'پاسخ ثبت شد. تحویل ممکن است با کمی تأخیر انجام شود.'];
        }

        return ['success' => true, 'message' => 'پاسخ ناشناس ارسال شد.'];
    }

    /**
     * @param array<string, mixed> $targetUser
     * @return array{success:bool,message:string}
     */
    public function blockSenderFromMessage(array $targetUser, int $messageId): array
    {
        $targetUserId = (int) ($targetUser['id'] ?? 0);
        if ($targetUserId <= 0) {
            return ['success' => false, 'message' => 'کاربر نامعتبر است.'];
        }

        $message = $this->messageRepository->findById($messageId);
        if ($message === null || (int) $message['target_user_id'] !== $targetUserId) {
            return ['success' => false, 'message' => 'پیام برای بلاک پیدا نشد.'];
        }

        $senderTelegramUserId = (int) $message['sender_telegram_user_id'];
        if ($senderTelegramUserId <= 0) {
            return ['success' => false, 'message' => 'شناسه ارسال‌کننده نامعتبر است.'];
        }

        $isUpdated = $this->blockRepository->blockSender($targetUserId, $senderTelegramUserId);
        if (!$isUpdated) {
            return ['success' => true, 'message' => 'این فرستنده قبلاً بلاک شده بود.'];
        }

        return ['success' => true, 'message' => 'ارسال‌کننده برای شما بلاک شد.'];
    }

    /**
     * @return array<string, array<int, array<int, array<string, string>>>>
     */
    private function buildMessageActionKeyboard(int $messageId, int $targetTelegramUserId): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => 'پاسخ',
                        'callback_data' => $this->callbackSigner->issue('r', $messageId, $targetTelegramUserId, self::ACTION_BUTTON_TTL_SECONDS),
                    ],
                    [
                        'text' => 'بلاک',
                        'callback_data' => $this->callbackSigner->issue('b', $messageId, $targetTelegramUserId, self::ACTION_BUTTON_TTL_SECONDS),
                    ],
                    [
                        'text' => 'گزارش',
                        'callback_data' => $this->callbackSigner->issue('p', $messageId, $targetTelegramUserId, self::ACTION_BUTTON_TTL_SECONDS),
                    ],
                ],
            ],
        ];
    }

    private function createThreadId(): string
    {
        return bin2hex(random_bytes(12));
    }

    private function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }
}
