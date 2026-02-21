<?php

declare(strict_types=1);

namespace PhpBot\Security;

final class TextSanitizer
{
    public function sanitizeIncomingText(string $text): string
    {
        $cleanText = trim($text);
        $cleanText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleanText) ?? '';
        $cleanText = preg_replace("/\n{3,}/", "\n\n", $cleanText) ?? $cleanText;

        return trim($cleanText);
    }

    public function containsForbiddenWords(string $text, array $forbiddenWords): bool
    {
        if ($text === '' || $forbiddenWords === []) {
            return false;
        }

        $normalizedText = $this->toLower($text);
        foreach ($forbiddenWords as $forbiddenWord) {
            $word = trim((string) $forbiddenWord);
            if ($word === '') {
                continue;
            }

            if (str_contains($normalizedText, $this->toLower($word))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function parseForbiddenWords(string $rawWords): array
    {
        $words = preg_split('/[\r\n,]+/u', $rawWords) ?: [];
        $normalizedWords = [];
        foreach ($words as $word) {
            $word = $this->sanitizeIncomingText($word);
            $wordLength = $this->stringLength($word);
            if ($word === '' || $wordLength < 2 || $wordLength > 32) {
                continue;
            }
            $normalizedWords[] = $word;
        }

        return array_values(array_unique($normalizedWords));
    }

    public function makeContentHash(?string $text, ?string $mediaFileId): string
    {
        $textPart = $this->toLower($this->sanitizeIncomingText((string) $text));
        $mediaPart = trim((string) $mediaFileId);

        return hash('sha256', $textPart . '|' . $mediaPart);
    }

    public function preview(?string $text, int $maxLength = 60): string
    {
        $value = $this->sanitizeIncomingText((string) $text);
        if ($value === '') {
            return '(بدون متن)';
        }

        if ($this->stringLength($value) <= $maxLength) {
            return $value;
        }

        return $this->stringSlice($value, 0, $maxLength - 1) . '...';
    }

    private function toLower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    private function stringSlice(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length, 'UTF-8');
        }

        return substr($value, $start, $length);
    }
}
