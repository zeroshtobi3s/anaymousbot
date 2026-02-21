<?php

declare(strict_types=1);

namespace PhpBot\Utils;

final class Logger
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
        $directory = dirname($logFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $safeContext = $this->sanitizeContext($context);
        $line = sprintf('[%s] %s %s', date('Y-m-d H:i:s'), $level, $message);
        if ($safeContext !== []) {
            $encodedContext = json_encode(
                $safeContext,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            if ($encodedContext !== false) {
                $line .= ' ' . $encodedContext;
            }
        }

        @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $masked = [];
        foreach ($context as $key => $value) {
            if ($this->shouldMask($key)) {
                $masked[$key] = '***';
                continue;
            }

            if (is_array($value)) {
                $masked[$key] = $this->sanitizeContext($value);
                continue;
            }

            if (is_object($value)) {
                $masked[$key] = sprintf('object(%s)', $value::class);
                continue;
            }

            $masked[$key] = $value;
        }

        return $masked;
    }

    private function shouldMask(string $key): bool
    {
        $normalizedKey = strtolower($key);
        foreach (['token', 'secret', 'pass', 'authorization'] as $sensitiveWord) {
            if (str_contains($normalizedKey, $sensitiveWord)) {
                return true;
            }
        }

        return false;
    }
}

