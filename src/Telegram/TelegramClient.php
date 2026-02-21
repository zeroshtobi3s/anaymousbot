<?php

declare(strict_types=1);

namespace PhpBot\Telegram;

use PhpBot\Utils\Logger;
use RuntimeException;

final class TelegramClient
{
    private string $apiBaseUrl;
    private Logger $logger;
    private ?string $caFile;

    public function __construct(string $botToken, Logger $logger, ?string $caFile = null)
    {
        $this->apiBaseUrl = 'https://api.telegram.org/bot' . $botToken . '/';
        $this->logger = $logger;
        $this->caFile = $caFile;
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed>
     */
    public function sendMessage(
        int $chatId,
        string $text,
        ?array $replyMarkup = null,
        bool $disableWebPagePreview = true
    ): array {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => $disableWebPagePreview,
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->request('sendMessage', $params, 20);
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed>
     */
    public function sendPhoto(
        int $chatId,
        string $fileId,
        ?string $caption = null,
        ?array $replyMarkup = null
    ): array {
        $params = [
            'chat_id' => $chatId,
            'photo' => $fileId,
        ];
        if ($caption !== null && $caption !== '') {
            $params['caption'] = $caption;
        }
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->request('sendPhoto', $params, 25);
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     */
    public function editMessageReplyMarkup(int $chatId, int $messageId, ?array $replyMarkup = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup ?? ['inline_keyboard' => []],
        ];
        $this->request('editMessageReplyMarkup', $params, 20);
    }

    public function answerCallbackQuery(
        string $callbackQueryId,
        string $text = '',
        bool $showAlert = false,
        int $cacheTime = 0
    ): void {
        $params = [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
            'cache_time' => $cacheTime,
        ];
        $this->request('answerCallbackQuery', $params, 15);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeoutSeconds): array
    {
        $result = $this->request(
            'getUpdates',
            [
                'offset' => $offset,
                'timeout' => $timeoutSeconds,
                'allowed_updates' => ['message', 'callback_query'],
            ],
            $timeoutSeconds + 10
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMe(): array
    {
        return $this->request('getMe', [], 15);
    }

    /**
     * @return array<string, mixed>
     */
    public function getChatMember(string $chatId, int $userId): array
    {
        $result = $this->request(
            'getChatMember',
            [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ],
            15
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    private function request(string $method, array $params, int $timeoutSeconds): array
    {
        $curlHandle = curl_init($this->apiBaseUrl . $method);
        if ($curlHandle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $preparedParams = $this->prepareParams($params);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($preparedParams),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($this->caFile !== null && is_file($this->caFile)) {
            $curlOptions[CURLOPT_CAINFO] = $this->caFile;
        }

        curl_setopt_array($curlHandle, $curlOptions);

        $responseBody = curl_exec($curlHandle);
        if ($responseBody === false) {
            $error = curl_error($curlHandle);
            curl_close($curlHandle);
            $this->logger->error('Telegram request failed at transport level.', [
                'method' => $method,
                'error' => $error,
            ]);
            throw new RuntimeException('Telegram connection failed.');
        }

        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        curl_close($curlHandle);

        $decodedResponse = json_decode($responseBody, true);
        if (!is_array($decodedResponse)) {
            $this->logger->error('Telegram response is not valid JSON.', [
                'method' => $method,
                'http_code' => $httpCode,
            ]);
            throw new RuntimeException('Invalid response from Telegram.');
        }

        if (($decodedResponse['ok'] ?? false) !== true) {
            $description = (string) ($decodedResponse['description'] ?? 'Telegram API error.');
            $this->logger->warning('Telegram API returned error.', [
                'method' => $method,
                'http_code' => $httpCode,
                'description' => $description,
            ]);
            throw new RuntimeException($description);
        }

        $result = $decodedResponse['result'] ?? [];
        if (is_array($result)) {
            /** @var array<string, mixed>|array<int, array<string, mixed>> $result */
            return $result;
        }

        // Some Telegram methods (e.g. answerCallbackQuery) return boolean true.
        return [];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, scalar|null>
     */
    private function prepareParams(array $params): array
    {
        $preparedParams = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $encodedValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encodedValue === false) {
                    continue;
                }
                $preparedParams[$key] = $encodedValue;
                continue;
            }

            if (is_bool($value)) {
                $preparedParams[$key] = $value ? 'true' : 'false';
                continue;
            }

            if (is_scalar($value)) {
                $preparedParams[$key] = $value;
            }
        }

        return $preparedParams;
    }
}
