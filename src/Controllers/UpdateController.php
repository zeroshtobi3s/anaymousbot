<?php

declare(strict_types=1);

namespace PhpBot\Controllers;

use PhpBot\Config\AppConfig;
use PhpBot\Repositories\BlockRepository;
use PhpBot\Repositories\MessageRepository;
use PhpBot\Repositories\ReportRepository;
use PhpBot\Security\CallbackSigner;
use PhpBot\Security\TextSanitizer;
use PhpBot\Services\AnonymousMessageService;
use PhpBot\Services\BotIdentityService;
use PhpBot\Services\ConversationService;
use PhpBot\Services\MaintenanceService;
use PhpBot\Services\ReportService;
use PhpBot\Services\SettingsService;
use PhpBot\Services\UserService;
use PhpBot\Telegram\TelegramClient;
use PhpBot\Utils\Logger;
use Throwable;

final class UpdateController
{
    private const JOIN_CHECK_ACTION = 'jc';
    private const JOIN_STATUS_JOINED = 'joined';
    private const JOIN_STATUS_NOT_JOINED = 'not_joined';
    private const JOIN_STATUS_UNAVAILABLE = 'unavailable';
    private const JOIN_PROMPT_COOLDOWN_SECONDS = 90;
    private const JOIN_STATUS_CACHE_TTL_JOINED_SECONDS = 3;
    private const JOIN_STATUS_CACHE_TTL_NOT_JOINED_SECONDS = 20;
    private const JOIN_STATUS_CACHE_TTL_UNAVAILABLE_SECONDS = 120;
    private const HELP_FALLBACK_COOLDOWN_SECONDS = 45;
    /** @var string[] */
    private const ACTIVE_CHAT_MEMBER_STATUSES = ['creator', 'administrator', 'member', 'restricted'];

    private AppConfig $config;
    private Logger $logger;
    private TelegramClient $telegramClient;
    private UserService $userService;
    private SettingsService $settingsService;
    private ConversationService $conversationService;
    private AnonymousMessageService $anonymousMessageService;
    private ReportService $reportService;
    private BotIdentityService $botIdentityService;
    private MessageRepository $messageRepository;
    private BlockRepository $blockRepository;
    private ReportRepository $reportRepository;
    private CallbackSigner $callbackSigner;
    private TextSanitizer $textSanitizer;
    private MaintenanceService $maintenanceService;
    /** @var array<int, int> */
    private array $joinPromptLastSentAt = [];
    /** @var array<int, int> */
    private array $helpFallbackLastSentAt = [];
    /** @var array<int, array{status:string,expires_at:int}> */
    private array $joinStatusCache = [];

    public function __construct(
        AppConfig $config,
        Logger $logger,
        TelegramClient $telegramClient,
        UserService $userService,
        SettingsService $settingsService,
        ConversationService $conversationService,
        AnonymousMessageService $anonymousMessageService,
        ReportService $reportService,
        BotIdentityService $botIdentityService,
        MessageRepository $messageRepository,
        BlockRepository $blockRepository,
        ReportRepository $reportRepository,
        CallbackSigner $callbackSigner,
        TextSanitizer $textSanitizer,
        MaintenanceService $maintenanceService
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->telegramClient = $telegramClient;
        $this->userService = $userService;
        $this->settingsService = $settingsService;
        $this->conversationService = $conversationService;
        $this->anonymousMessageService = $anonymousMessageService;
        $this->reportService = $reportService;
        $this->botIdentityService = $botIdentityService;
        $this->messageRepository = $messageRepository;
        $this->blockRepository = $blockRepository;
        $this->reportRepository = $reportRepository;
        $this->callbackSigner = $callbackSigner;
        $this->textSanitizer = $textSanitizer;
        $this->maintenanceService = $maintenanceService;
    }

    /**
     * @param array<string, mixed> $update
     */
    public function handleUpdate(array $update): void
    {
        try {
            $this->maintenanceService->runIfDue();

            if (isset($update['callback_query']) && is_array($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);

                return;
            }

            if (isset($update['message']) && is_array($update['message'])) {
                $this->handleMessage($update['message']);
            }
        } catch (Throwable $throwable) {
            $this->logger->error('Update handling failed.', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleMessage(array $message): void
    {
        $from = $message['from'] ?? null;
        if (!is_array($from)) {
            return;
        }

        $telegramUserId = (int) ($from['id'] ?? 0);
        if ($telegramUserId <= 0) {
            return;
        }

        $chatId = (int) ($message['chat']['id'] ?? $telegramUserId);
        if ($chatId <= 0) {
            return;
        }

        $user = $this->userService->ensureUser($from);
        $text = isset($message['text']) ? trim((string) $message['text']) : null;
        $command = $text !== null ? $this->extractCommand($text) : null;
        $quickCommandPreview = $text !== null ? $this->extractQuickCommand($text) : null;
        $shouldSendJoinPrompt = $command !== null || $quickCommandPreview !== null;
        if (!$shouldSendJoinPrompt && $text !== null) {
            $shouldSendJoinPrompt = $this->isPersonalLinkRequest($text);
        }

        if (!$this->enforceChannelJoinGate($chatId, $telegramUserId, null, $shouldSendJoinPrompt)) {
            return;
        }

        if ($command !== null) {
            $this->handleCommand($user, $chatId, $command['command'], $command['param']);

            return;
        }

        $state = $this->conversationService->getState($telegramUserId);
        if ($state !== null) {
            $this->handleStateMessage($user, $chatId, $message, $state);

            return;
        }

        if ($text !== null) {
            $quickCommand = $quickCommandPreview;
            if ($quickCommand !== null) {
                $this->handleCommand($user, $chatId, $quickCommand, '');

                return;
            }

            if ($this->isPersonalLinkRequest($text)) {
                $this->sendPersonalLinkMessage($chatId, $user);

                return;
            }
        }

        if ($this->tryHandleInlineReply($user, $chatId, $message)) {
            return;
        }

        if ($this->shouldSendHelpFallbackNow($telegramUserId)) {
            $this->sendHelpMessage($chatId);
        }
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackQueryId = (string) ($callbackQuery['id'] ?? '');
        $token = trim((string) ($callbackQuery['data'] ?? ''));
        $from = $callbackQuery['from'] ?? null;

        if ($callbackQueryId === '' || $token === '' || !is_array($from)) {
            return;
        }

        $telegramUserId = (int) ($from['id'] ?? 0);
        if ($telegramUserId <= 0) {
            return;
        }

        $chatId = (int) (($callbackQuery['message']['chat']['id'] ?? $telegramUserId));
        if ($chatId <= 0) {
            $chatId = $telegramUserId;
        }

        $user = $this->userService->ensureUser($from);
        $decodedToken = $this->callbackSigner->verify($token);
        if ($decodedToken === null) {
            $this->safeAnswerCallback($callbackQueryId, 'این دکمه نامعتبر یا منقضی شده است.');

            return;
        }

        if ((int) $decodedToken['telegram_user_id'] !== $telegramUserId) {
            $this->safeAnswerCallback($callbackQueryId, 'این دکمه متعلق به شما نیست.');

            return;
        }

        $action = $decodedToken['action'];
        $referenceId = (int) $decodedToken['reference_id'];

        if ($action === self::JOIN_CHECK_ACTION) {
            if ($this->enforceChannelJoinGate($chatId, $telegramUserId, $callbackQueryId, false, true)) {
                $this->safeAnswerCallback($callbackQueryId, 'عضویت شما تایید شد.');
                $this->dismissJoinCheckKeyboard($callbackQuery);
                $this->safeSendMessage(
                    $chatId,
                    'عضویت شما تایید شد. حالا می‌توانید از همه امکانات ربات استفاده کنید.',
                    $this->buildMainMenuKeyboard()
                );
            }

            return;
        }

        if (!$this->enforceChannelJoinGate($chatId, $telegramUserId, $callbackQueryId, false)) {
            return;
        }

        switch ($action) {
            case 'r':
                $this->conversationService->setAwaitingReply($telegramUserId, $referenceId);
                $this->safeAnswerCallback($callbackQueryId, 'متن پاسخ را ارسال کنید.');
                $this->safeSendMessage(
                    $telegramUserId,
                    "پاسخ ناشناس را بنویسید.\nشما می‌توانید روی همان پیام هم Reply بکنید.\nبرای لغو: /cancel",
                    $this->buildMainMenuKeyboard()
                );
                break;

            case 'b':
                $result = $this->anonymousMessageService->blockSenderFromMessage($user, $referenceId);
                $this->safeAnswerCallback($callbackQueryId, $result['message']);
                $this->safeSendMessage($telegramUserId, $result['message']);
                break;

            case 'p':
                $result = $this->reportService->reportMessage($user, $referenceId);
                $this->safeAnswerCallback($callbackQueryId, $result['message']);
                $this->safeSendMessage($telegramUserId, $result['message']);
                break;

            case 'ab':
                $result = $this->reportService->adminBlockSender($telegramUserId, $referenceId);
                $this->safeAnswerCallback($callbackQueryId, $result['message']);
                $this->safeSendMessage($telegramUserId, $result['message']);
                break;

            case 'sa':
                $updatedSettings = $this->settingsService->toggleAcceptMessages($user);
                $this->safeAnswerCallback($callbackQueryId, 'تنظیم انجام شد.');
                $this->sendSettingsMessage($telegramUserId, $updatedSettings, $telegramUserId);
                break;

            case 'sm':
                $updatedSettings = $this->settingsService->toggleAllowMedia($user);
                $this->safeAnswerCallback($callbackQueryId, 'تنظیم انجام شد.');
                $this->sendSettingsMessage($telegramUserId, $updatedSettings, $telegramUserId);
                break;

            case 'sw':
                $this->conversationService->setAwaitingBannedWords($telegramUserId);
                $this->safeAnswerCallback($callbackQueryId, 'کلمات را ارسال کنید.');
                $this->safeSendMessage(
                    $telegramUserId,
                    "کلمات ممنوع را با ویرگول یا خط جدید بفرستید.\nبرای پاک کردن لیست: /clear\nبرای لغو: /cancel"
                );
                break;

            default:
                $this->safeAnswerCallback($callbackQueryId, 'اکشن نامعتبر است.');
                break;
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function handleCommand(array $user, int $chatId, string $command, string $param): void
    {
        $telegramUserId = (int) ($user['telegram_user_id'] ?? 0);

        switch ($command) {
            case '/start':
                $this->handleStartCommand($user, $chatId, $param);
                break;

            case '/cancel':
                $this->conversationService->clearState($telegramUserId);
                $this->safeSendMessage($chatId, 'عملیات لغو شد.', $this->buildMainMenuKeyboard());
                break;

            case '/inbox':
                $this->sendInbox($chatId, $user);
                break;

            case '/stats':
                $this->sendStats($chatId, $user);
                break;

            case '/settings':
                $settings = $this->userService->getSettings($user);
                $this->sendSettingsMessage($chatId, $settings, $telegramUserId);
                break;

            case '/link':
                $this->sendPersonalLinkMessage($chatId, $user);
                break;

            case '/joincheck':
                if ($this->enforceChannelJoinGate($chatId, $telegramUserId, null, true, true)) {
                    $this->safeSendMessage(
                        $chatId,
                        'عضویت شما تایید شد و همه امکانات ربات فعال است.',
                        $this->buildMainMenuKeyboard()
                    );
                }
                break;

            case '/banwords':
                if ($param === '') {
                    $this->conversationService->setAwaitingBannedWords($telegramUserId);
                    $this->safeSendMessage(
                        $chatId,
                        "کلمات ممنوع را با ویرگول یا خط جدید بفرستید.\nبرای لغو: /cancel"
                    );
                } else {
                    $settings = $this->settingsService->updateBannedWordsFromText($user, $param);
                    $this->sendSettingsMessage($chatId, $settings, $telegramUserId);
                }
                break;

            case '/help':
            case '/menu':
                $this->sendHelpMessage($chatId);
                break;

            default:
                $this->safeSendMessage($chatId, 'دستور ناشناخته است. برای راهنما: /help');
                break;
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function handleStartCommand(array $user, int $chatId, string $startParam): void
    {
        $telegramUserId = (int) ($user['telegram_user_id'] ?? 0);
        $cleanStartParam = trim(strtolower($startParam));

        if ($cleanStartParam !== '') {
            $targetUser = $this->userService->findBySlug($cleanStartParam);
            if ($targetUser === null) {
                $this->safeSendMessage($chatId, 'لینک نامعتبر است یا کاربر وجود ندارد.');

                return;
            }

            if ((int) $targetUser['id'] === (int) $user['id']) {
                $this->safeSendMessage($chatId, 'شما نمی‌توانید به خودتان پیام ناشناس بدهید.');

                return;
            }

            if ((int) ($targetUser['is_active'] ?? 0) !== 1) {
                $this->safeSendMessage($chatId, 'این کاربر در حال حاضر پیام دریافت نمی‌کند.');

                return;
            }

            $this->conversationService->setAwaitingAnonymousMessage(
                $telegramUserId,
                (int) $targetUser['id']
            );

            $targetName = $this->userService->getDisplayName($targetUser);
            $this->safeSendMessage(
                $chatId,
                "شما در حال ارسال پیام ناشناس به {$targetName} هستید.\n"
                . "متن یا عکس خود را ارسال کنید.\n"
                . "برای لغو: /cancel"
            );

            return;
        }

        $this->conversationService->clearState($telegramUserId);
        $this->safeSendMessage(
            $chatId,
            "به ربات پیام ناشناس خوش آمدید.\n"
            . "با لینک اختصاصی خود می‌توانید پیام ناشناس دریافت کنید.\n"
            . "برای دریافت لینک، روی دکمه «لینک من» بزنید یا دستور /link را ارسال کنید.\n\n"
            . "دستورات:\n"
            . "/link - لینک اختصاصی من\n"
            . "/inbox - 10 پیام آخر\n"
            . "/stats - آمار\n"
            . "/settings - تنظیمات دریافت پیام",
            $this->buildMainMenuKeyboard()
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     * @param array{state_name:string,payload:array<string,mixed>,updated_at:string} $state
     */
    private function handleStateMessage(array $user, int $chatId, array $message, array $state): void
    {
        $stateName = $state['state_name'];
        switch ($stateName) {
            case ConversationService::STATE_AWAITING_ANONYMOUS_MESSAGE:
                $this->handleAwaitingAnonymousMessageState($user, $chatId, $message, $state['payload']);
                break;

            case ConversationService::STATE_AWAITING_REPLY:
                $this->handleAwaitingReplyState($user, $chatId, $message, $state['payload']);
                break;

            case ConversationService::STATE_AWAITING_BANNED_WORDS:
                $this->handleAwaitingBannedWordsState($user, $chatId, $message);
                break;

            default:
                $this->conversationService->clearState((int) $user['telegram_user_id']);
                $this->safeSendMessage($chatId, 'وضعیت گفتگو ریست شد. دوباره تلاش کنید.');
                break;
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     * @param array<string, mixed> $payload
     */
    private function handleAwaitingAnonymousMessageState(array $user, int $chatId, array $message, array $payload): void
    {
        $targetUserId = (int) ($payload['target_user_id'] ?? 0);
        $targetUser = $this->userService->findById($targetUserId);
        if ($targetUser === null) {
            $this->conversationService->clearState((int) $user['telegram_user_id']);
            $this->safeSendMessage($chatId, 'کاربر مقصد پیدا نشد. دوباره از لینک شروع کنید.');

            return;
        }

        $anonymousPayload = $this->extractAnonymousPayload($message);
        if ($anonymousPayload === null) {
            $this->safeSendMessage(
                $chatId,
                "فقط پیام متنی یا عکس پشتیبانی می‌شود.\n"
                . "برای لغو: /cancel"
            );

            return;
        }

        $result = $this->anonymousMessageService->submitAnonymousMessage($user, $targetUser, $anonymousPayload);
        $this->safeSendMessage($chatId, $result['message']);
        if ($result['success']) {
            $this->conversationService->clearState((int) $user['telegram_user_id']);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     * @param array<string, mixed> $payload
     */
    private function handleAwaitingReplyState(array $user, int $chatId, array $message, array $payload): void
    {
        $messageIdFromReply = $this->extractReplyReferenceMessageId(
            $message,
            (int) ($user['telegram_user_id'] ?? 0)
        );
        $messageId = $messageIdFromReply ?? (int) ($payload['message_id'] ?? 0);
        if ($messageId <= 0) {
            $this->conversationService->clearState((int) $user['telegram_user_id']);
            $this->safeSendMessage($chatId, 'اطلاعات پاسخ ناقص است. دوباره تلاش کنید.');

            return;
        }

        $replyText = $this->textSanitizer->sanitizeIncomingText((string) ($message['text'] ?? ''));
        if ($replyText === '') {
            $this->safeSendMessage($chatId, 'لطفاً فقط متن پاسخ را ارسال کنید. برای لغو: /cancel');

            return;
        }

        $result = $this->anonymousMessageService->sendAnonymousReply($user, $messageId, $replyText);
        $this->safeSendMessage($chatId, $result['message']);
        if ($result['success']) {
            $this->conversationService->clearState((int) $user['telegram_user_id']);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     */
    private function handleAwaitingBannedWordsState(array $user, int $chatId, array $message): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            $this->safeSendMessage($chatId, 'متن کلمات ممنوع خالی است. برای لغو: /cancel');

            return;
        }

        $normalizedText = strtolower($text);
        if ($normalizedText === '/clear' || $normalizedText === 'پاک') {
            $settings = $this->settingsService->clearBannedWords($user);
        } else {
            $settings = $this->settingsService->updateBannedWordsFromText($user, $text);
        }

        $this->conversationService->clearState((int) $user['telegram_user_id']);
        $this->sendSettingsMessage($chatId, $settings, (int) $user['telegram_user_id']);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function sendInbox(int $chatId, array $user): void
    {
        $inboxMessages = $this->messageRepository->getInbox((int) $user['id'], 10);
        if ($inboxMessages === []) {
            $this->safeSendMessage($chatId, 'صندوق ورودی شما خالی است.');

            return;
        }

        $lines = ["10 پیام آخر شما:"];
        $index = 1;
        foreach ($inboxMessages as $message) {
            $messageType = (string) ($message['message_type'] ?? 'text');
            $label = $messageType === 'photo' ? '📷 عکس' : '📝 متن';
            $preview = $this->textSanitizer->preview((string) ($message['text'] ?? ''), 45);
            $createdAt = (string) ($message['created_at'] ?? '');
            $lines[] = sprintf('%d) %s | %s | %s', $index, $createdAt, $label, $preview);
            $index++;
        }

        $this->safeSendMessage($chatId, implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function sendStats(int $chatId, array $user): void
    {
        $targetUserId = (int) $user['id'];
        $telegramUserId = (int) $user['telegram_user_id'];

        $receivedMessages = $this->messageRepository->countReceivedByTarget($targetUserId);
        $sentMessages = $this->messageRepository->countSentBySender($telegramUserId);
        $blockCount = $this->blockRepository->countByTarget($targetUserId);
        $reportCount = $this->reportRepository->countByReporter($targetUserId);
        $reportsOnTarget = $this->messageRepository->countReportsOnTarget($targetUserId);

        $statsText = "آمار شما:\n"
            . "• پیام‌های دریافتی: {$receivedMessages}\n"
            . "• پیام‌های ارسالی ناشناس: {$sentMessages}\n"
            . "• فرستنده‌های بلاک‌شده: {$blockCount}\n"
            . "• گزارش‌های ثبت‌شده توسط شما: {$reportCount}\n"
            . "• گزارش‌های مربوط به پیام‌های شما: {$reportsOnTarget}";

        $this->safeSendMessage($chatId, $statsText);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function sendSettingsMessage(int $chatId, array $settings, int $telegramUserId): void
    {
        $text = $this->settingsService->formatSettingsText($settings);
        $keyboard = $this->settingsService->buildSettingsKeyboard($telegramUserId);
        $this->safeSendMessage($chatId, $text, $keyboard);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function sendPersonalLinkMessage(int $chatId, array $user): void
    {
        $publicSlug = trim((string) ($user['public_slug'] ?? ''));
        if ($publicSlug === '') {
            $this->safeSendMessage($chatId, 'ساخت لینک شما با خطا مواجه شد. دوباره /start را بزنید.');

            return;
        }

        $personalLink = $this->buildPersonalLink($publicSlug);
        if ($personalLink === null) {
            $this->safeSendMessage(
                $chatId,
                "لینک شما: {$publicSlug}\n"
                . 'برای ساخت deep-link، مقدار BOT_USERNAME یا APP_BASE_URL را در .env تنظیم کنید.'
            );

            return;
        }

        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => 'لینک من',
                    'url' => $personalLink,
                ],
            ]],
        ];

        $this->safeSendMessage(
            $chatId,
            "لینک اختصاصی شما:\n{$personalLink}",
            $keyboard
        );
    }

    private function buildPersonalLink(string $publicSlug): ?string
    {
        $appBaseUrl = $this->config->appBaseUrl();
        if ($appBaseUrl !== null && $appBaseUrl !== '') {
            return $appBaseUrl . '/?start=' . urlencode($publicSlug);
        }

        $botUsername = $this->botIdentityService->getBotUsername();
        if ($botUsername === null || $botUsername === '') {
            return null;
        }

        return sprintf('https://t.me/%s?start=%s', $botUsername, urlencode($publicSlug));
    }

    private function sendHelpMessage(int $chatId): void
    {
        $helpText = "برای شروع یکی از گزینه‌های زیر را بزنید:\n"
            . "/start - شروع / بازگشت به منوی اصلی\n"
            . "/link - لینک اختصاصی من\n"
            . "/inbox - 10 پیام آخر\n"
            . "/stats - آمار\n"
            . "/settings - تنظیمات\n"
            . "/joincheck - بررسی عضویت کانال\n\n"
            . "عضویت در کانال برای استفاده از ربات اجباری است.\n"
            . "نکته: برای پاسخ ناشناس، روی همان پیام Reply کنید یا دکمه «پاسخ» را بزنید.";
        $this->safeSendMessage($chatId, $helpText, $this->buildMainMenuKeyboard());
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    private function extractAnonymousPayload(array $message): ?array
    {
        $text = $this->textSanitizer->sanitizeIncomingText((string) ($message['text'] ?? ''));
        if ($text !== '' && !str_starts_with($text, '/')) {
            return [
                'type' => 'text',
                'text' => $text,
                'media_file_id' => null,
                'photo_size' => null,
            ];
        }

        $photos = $message['photo'] ?? null;
        if (is_array($photos) && $photos !== []) {
            $lastPhoto = end($photos);
            if (is_array($lastPhoto)) {
                $caption = $this->textSanitizer->sanitizeIncomingText((string) ($message['caption'] ?? ''));

                return [
                    'type' => 'photo',
                    'text' => $caption,
                    'media_file_id' => (string) ($lastPhoto['file_id'] ?? ''),
                    'photo_size' => (int) ($lastPhoto['file_size'] ?? 0),
                ];
            }
        }

        return null;
    }

    /**
     * @return array{command:string,param:string}|null
     */
    private function extractCommand(string $text): ?array
    {
        $cleanText = trim($text);
        if (!str_starts_with($cleanText, '/')) {
            return null;
        }

        $parts = preg_split('/\s+/', $cleanText, 2) ?: [];
        $commandPart = strtolower((string) ($parts[0] ?? ''));
        if ($commandPart === '') {
            return null;
        }

        $command = explode('@', $commandPart, 2)[0];
        $param = trim((string) ($parts[1] ?? ''));

        return ['command' => $command, 'param' => $param];
    }

    private function extractQuickCommand(string $text): ?string
    {
        $normalized = $this->normalizeQuickInput($text);
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'تنظیمات', 'settings' => '/settings',
            'صندوق ورودی', 'صندوق', 'inbox' => '/inbox',
            'آمار', 'stats' => '/stats',
            'بررسی عضویت', 'عضو شدم', 'عضو شدم بررسی عضویت', 'بررسی عضویت عضو شدم', 'join check', 'check join', 'joincheck', 'check membership' => '/joincheck',
            'راهنما', 'help', 'menu', 'منو' => '/help',
            'شروع', 'استارت' => '/start',
            'لغو', 'cancel' => '/cancel',
            'لینک', 'لینک من', 'لینکم', 'my link', 'link' => '/link',
            default => null,
        };
    }

    private function normalizeQuickInput(string $text): string
    {
        $value = trim($text);
        if ($value === '') {
            return '';
        }

        $value = str_replace(["\u{200c}", "\u{200f}", "\u{200e}"], ' ', $value);
        $value = str_replace(['ي', 'ك'], ['ی', 'ک'], $value);
        $value = str_replace(['✅', '☑️', '✔️'], '', $value);
        $value = str_replace(['/', '\\', '|', '،', ',', '-', 'ـ'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     */
    private function tryHandleInlineReply(array $user, int $chatId, array $message): bool
    {
        $telegramUserId = (int) ($user['telegram_user_id'] ?? 0);
        if ($telegramUserId <= 0) {
            return false;
        }

        $referenceMessageId = $this->extractReplyReferenceMessageId($message, $telegramUserId);
        if ($referenceMessageId === null) {
            return false;
        }

        $replyText = $this->textSanitizer->sanitizeIncomingText((string) ($message['text'] ?? ''));
        if ($replyText === '') {
            $this->safeSendMessage(
                $chatId,
                'لطفاً متن پاسخ را ارسال کنید. برای لغو: /cancel',
                $this->buildMainMenuKeyboard()
            );

            return true;
        }

        $result = $this->anonymousMessageService->sendAnonymousReply($user, $referenceMessageId, $replyText);
        $this->safeSendMessage($chatId, $result['message'], $this->buildMainMenuKeyboard());

        return true;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function extractReplyReferenceMessageId(array $message, int $telegramUserId): ?int
    {
        $replyToMessage = $message['reply_to_message'] ?? null;
        if (!is_array($replyToMessage)) {
            return null;
        }

        $replyMarkup = $replyToMessage['reply_markup'] ?? null;
        if (!is_array($replyMarkup)) {
            return null;
        }

        $inlineKeyboard = $replyMarkup['inline_keyboard'] ?? null;
        if (!is_array($inlineKeyboard)) {
            return null;
        }

        foreach ($inlineKeyboard as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $button) {
                if (!is_array($button)) {
                    continue;
                }

                $callbackData = trim((string) ($button['callback_data'] ?? ''));
                if ($callbackData === '') {
                    continue;
                }

                $decoded = $this->callbackSigner->verify($callbackData);
                if ($decoded === null) {
                    continue;
                }

                if ((int) $decoded['telegram_user_id'] !== $telegramUserId) {
                    continue;
                }

                if ((string) $decoded['action'] !== 'r') {
                    continue;
                }

                $referenceId = (int) ($decoded['reference_id'] ?? 0);
                if ($referenceId > 0) {
                    return $referenceId;
                }
            }
        }

        return null;
    }

    private function isPersonalLinkRequest(string $text): bool
    {
        $value = $this->normalizeQuickInput($text);

        return in_array($value, ['لینک', 'لینک من', 'لینکم', 'link', 'my link'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => 'لینک من'],
                    ['text' => 'صندوق ورودی'],
                ],
                [
                    ['text' => 'تنظیمات'],
                    ['text' => 'آمار'],
                ],
                [
                    ['text' => 'راهنما'],
                    ['text' => 'بررسی عضویت'],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => 'پیام یا دستور را ارسال کنید',
        ];
    }

    private function enforceChannelJoinGate(
        int $chatId,
        int $telegramUserId,
        ?string $callbackQueryId = null,
        bool $sendJoinPromptMessage = true,
        bool $forceMembershipRefresh = false
    ): bool {
        if (!$this->config->requireChannelJoin()) {
            return true;
        }

        $channelUsername = $this->config->requiredChannelUsername();
        if ($channelUsername === null || $channelUsername === '') {
            return true;
        }

        $joinStatus = $this->resolveJoinStatus($channelUsername, $telegramUserId, $forceMembershipRefresh);
        if ($joinStatus === self::JOIN_STATUS_JOINED) {
            return true;
        }

        if ($callbackQueryId !== null) {
            if ($joinStatus === self::JOIN_STATUS_UNAVAILABLE) {
                $this->safeAnswerCallback(
                    $callbackQueryId,
                    'بررسی عضویت در دسترس نیست. ربات باید ادمین کانال باشد.'
                );
            } else {
                $this->safeAnswerCallback($callbackQueryId, 'هنوز عضو کانال نشدید.');
            }
        }

        if (!$sendJoinPromptMessage || !$this->shouldSendJoinPromptNow($telegramUserId)) {
            return false;
        }

        if ($joinStatus === self::JOIN_STATUS_UNAVAILABLE) {
            $this->safeSendMessage(
                $chatId,
                "بررسی عضویت الان ممکن نیست.\n"
                . "مدیر ربات باید ربات را در کانال ادمین کند و دوباره /joincheck را بزنید."
            );

            return false;
        }

        $this->sendChannelJoinRequiredMessage($chatId, $telegramUserId);

        return false;
    }

    private function resolveJoinStatus(
        string $channelUsername,
        int $telegramUserId,
        bool $forceRefresh = false
    ): string
    {
        if (!$forceRefresh) {
            $cachedStatus = $this->joinStatusCache[$telegramUserId] ?? null;
            if (
                is_array($cachedStatus)
                && isset($cachedStatus['status'], $cachedStatus['expires_at'])
                && (int) $cachedStatus['expires_at'] >= time()
            ) {
                return (string) $cachedStatus['status'];
            }
        }

        try {
            $member = $this->telegramClient->getChatMember($channelUsername, $telegramUserId);
            $status = strtolower(trim((string) ($member['status'] ?? '')));
            if (in_array($status, self::ACTIVE_CHAT_MEMBER_STATUSES, true)) {
                $this->setJoinStatusCache($telegramUserId, self::JOIN_STATUS_JOINED);

                return self::JOIN_STATUS_JOINED;
            }

            $this->setJoinStatusCache($telegramUserId, self::JOIN_STATUS_NOT_JOINED);

            return self::JOIN_STATUS_NOT_JOINED;
        } catch (Throwable $throwable) {
            $errorMessage = $throwable->getMessage();
            $this->logger->warning('Channel membership check failed.', [
                'chat_id' => $channelUsername,
                'telegram_user_id' => $telegramUserId,
                'error' => $errorMessage,
            ]);

            if (str_contains(strtolower($errorMessage), 'member list is inaccessible')) {
                $this->setJoinStatusCache($telegramUserId, self::JOIN_STATUS_UNAVAILABLE);

                return self::JOIN_STATUS_UNAVAILABLE;
            }

            $this->setJoinStatusCache($telegramUserId, self::JOIN_STATUS_NOT_JOINED);

            return self::JOIN_STATUS_NOT_JOINED;
        }
    }

    private function setJoinStatusCache(int $telegramUserId, string $status): void
    {
        $ttlSeconds = match ($status) {
            self::JOIN_STATUS_JOINED => self::JOIN_STATUS_CACHE_TTL_JOINED_SECONDS,
            self::JOIN_STATUS_UNAVAILABLE => self::JOIN_STATUS_CACHE_TTL_UNAVAILABLE_SECONDS,
            default => self::JOIN_STATUS_CACHE_TTL_NOT_JOINED_SECONDS,
        };

        $this->joinStatusCache[$telegramUserId] = [
            'status' => $status,
            'expires_at' => time() + $ttlSeconds,
        ];
    }

    private function shouldSendHelpFallbackNow(int $telegramUserId): bool
    {
        $now = time();
        $lastSentAt = $this->helpFallbackLastSentAt[$telegramUserId] ?? 0;
        if ($lastSentAt > 0 && ($now - $lastSentAt) < self::HELP_FALLBACK_COOLDOWN_SECONDS) {
            return false;
        }

        $this->helpFallbackLastSentAt[$telegramUserId] = $now;

        return true;
    }

    private function shouldSendJoinPromptNow(int $telegramUserId): bool
    {
        $now = time();
        $lastSentAt = $this->joinPromptLastSentAt[$telegramUserId] ?? 0;
        if ($lastSentAt > 0 && ($now - $lastSentAt) < self::JOIN_PROMPT_COOLDOWN_SECONDS) {
            return false;
        }

        $this->joinPromptLastSentAt[$telegramUserId] = $now;

        return true;
    }

    private function sendChannelJoinRequiredMessage(int $chatId, int $telegramUserId): void
    {
        $channelUsername = $this->config->requiredChannelUsername() ?? '@rceold';
        $channelUrl = $this->config->requiredChannelUrl() ?? 'https://t.me/rceold';

        $keyboard = [
            'inline_keyboard' => [
                [[
                    'text' => 'عضویت در کانال',
                    'url' => $channelUrl,
                ]],
                [[
                    'text' => '✅ عضو شدم / بررسی عضویت',
                    'callback_data' => $this->callbackSigner->issue(self::JOIN_CHECK_ACTION, 0, $telegramUserId, 3600),
                ]],
            ],
        ];

        $message = "برای استفاده از ربات، ابتدا باید در کانال {$channelUsername} عضو شوید.\n\n"
            . "بعد از عضویت، دکمه «عضو شدم / بررسی عضویت» را بزنید.";
        $this->safeSendMessage($chatId, $message, $keyboard);
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    private function dismissJoinCheckKeyboard(array $callbackQuery): void
    {
        $message = $callbackQuery['message'] ?? null;
        if (!is_array($message)) {
            return;
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($chatId <= 0 || $messageId <= 0) {
            return;
        }

        $this->safeEditMessageReplyMarkup($chatId, $messageId);
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     */
    private function safeSendMessage(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        try {
            $this->telegramClient->sendMessage($chatId, $text, $replyMarkup);
        } catch (Throwable $throwable) {
            $this->logger->warning('Failed to send Telegram message.', [
                'chat_id' => $chatId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     */
    private function safeEditMessageReplyMarkup(int $chatId, int $messageId, ?array $replyMarkup = null): void
    {
        try {
            $this->telegramClient->editMessageReplyMarkup($chatId, $messageId, $replyMarkup);
        } catch (Throwable $throwable) {
            $this->logger->warning('Failed to edit Telegram message reply markup.', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function safeAnswerCallback(string $callbackQueryId, string $text): void
    {
        try {
            $this->telegramClient->answerCallbackQuery($callbackQueryId, $text);
        } catch (Throwable $throwable) {
            $this->logger->warning('Failed to answer callback query.', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}





