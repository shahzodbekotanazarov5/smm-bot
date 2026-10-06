<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    private const SENSITIVE_KEYS = ['token', 'key', 'api_key', 'secret', 'password', 'webhook_secret', 'cron_token'];

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $path = dirname(__DIR__, 2) . '/logs/app.log';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $contextJson = $context !== [] ? json_encode(self::redactArray($context), JSON_UNESCAPED_UNICODE) : '';
        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            self::redactString($message),
            $contextJson
        );

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    public static function redactString(string $text): string
    {
        $text = preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot[REDACTED_TOKEN]', $text) ?? $text;

        return preg_replace('/([?&](?:key|token|api_key|secret)=)[^&\s]+/i', '$1[REDACTED]', $text) ?? $text;
    }

    private static function redactArray(array $context): array
    {
        array_walk_recursive($context, function (&$value, $key): void {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $value = '[REDACTED]';
            } elseif (is_string($value)) {
                $value = self::redactString($value);
            }
        });

        return $context;
    }
}
