<?php

declare(strict_types=1);

namespace PhpBot\Services;

use PhpBot\Security\CallbackSigner;
use PhpBot\Security\TextSanitizer;

final class SettingsService
{
    private UserService $userService;
    private TextSanitizer $textSanitizer;
    private CallbackSigner $callbackSigner;

    public function __construct(
        UserService $userService,
        TextSanitizer $textSanitizer,
        CallbackSigner $callbackSigner
    ) {
        $this->userService = $userService;
        $this->textSanitizer = $textSanitizer;
        $this->callbackSigner = $callbackSigner;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function toggleAcceptMessages(array $user): array
    {
        $settings = $this->userService->getSettings($user);
        $settings['accept_messages'] = !((bool) ($settings['accept_messages'] ?? true));
        $this->userService->updateSettings((int) $user['id'], $settings);

        return $settings;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function toggleAllowMedia(array $user): array
    {
        $settings = $this->userService->getSettings($user);
        $settings['allow_media'] = !((bool) ($settings['allow_media'] ?? true));
        $this->userService->updateSettings((int) $user['id'], $settings);

        return $settings;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function updateBannedWordsFromText(array $user, string $rawInput): array
    {
        $settings = $this->userService->getSettings($user);
        $settings['banned_words'] = $this->textSanitizer->parseForbiddenWords($rawInput);
        $this->userService->updateSettings((int) $user['id'], $settings);

        return $settings;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function clearBannedWords(array $user): array
    {
        $settings = $this->userService->getSettings($user);
        $settings['banned_words'] = [];
        $this->userService->updateSettings((int) $user['id'], $settings);

        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function formatSettingsText(array $settings): string
    {
        $statusMessages = ((bool) ($settings['accept_messages'] ?? true)) ? 'فعال' : 'غیرفعال';
        $mediaMode = ((bool) ($settings['allow_media'] ?? true)) ? 'متن + مدیا' : 'فقط متن';

        $bannedWords = $settings['banned_words'] ?? [];
        if (!is_array($bannedWords) || $bannedWords === []) {
            $bannedWordsText = 'ندارد';
        } else {
            $bannedWordsText = implode('، ', array_slice(array_map('strval', $bannedWords), 0, 20));
        }

        return "تنظیمات فعلی شما:\n"
            . "• دریافت پیام: {$statusMessages}\n"
            . "• حالت پیام: {$mediaMode}\n"
            . "• کلمات ممنوع: {$bannedWordsText}\n\n"
            . 'برای ویرایش روی دکمه‌های زیر بزنید.';
    }

    /**
     * @return array<string, array<int, array<int, array<string, string>>>>
     */
    public function buildSettingsKeyboard(int $telegramUserId): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => 'فعال/غیرفعال دریافت پیام',
                        'callback_data' => $this->callbackSigner->issue('sa', 0, $telegramUserId, 7200),
                    ],
                ],
                [
                    [
                        'text' => 'فقط متن یا متن+مدیا',
                        'callback_data' => $this->callbackSigner->issue('sm', 0, $telegramUserId, 7200),
                    ],
                ],
                [
                    [
                        'text' => 'تنظیم کلمات ممنوع',
                        'callback_data' => $this->callbackSigner->issue('sw', 0, $telegramUserId, 7200),
                    ],
                ],
            ],
        ];
    }
}

