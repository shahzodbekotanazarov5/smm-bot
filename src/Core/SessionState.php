<?php

declare(strict_types=1);

namespace App\Core;

final class SessionState
{
    private const STALE_MINUTES = 30;

    public static function get(int $telegramId): ?array
    {
        // Staleness is compared entirely in SQL (NOW() vs updated_at, both
        // computed by MySQL) rather than pulling the timestamp into PHP and
        // using strtotime()/time() — those run under PHP's configured
        // timezone, which won't generally match the DB server's, and a
        // multi-hour skew there made every state look instantly "stale".
        $row = Database::fetchOne(
            'SELECT step, payload FROM user_states
             WHERE telegram_id = :tid AND step IS NOT NULL
               AND updated_at >= NOW() - INTERVAL ' . self::STALE_MINUTES . ' MINUTE',
            ['tid' => $telegramId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'step' => $row['step'],
            'payload' => $row['payload'] !== null ? (json_decode((string) $row['payload'], true) ?? []) : [],
        ];
    }

    public static function set(int $telegramId, string $step, array $payload = []): void
    {
        Database::execute(
            'INSERT INTO user_states (telegram_id, step, payload, updated_at)
             VALUES (:tid, :step, :payload, NOW())
             ON DUPLICATE KEY UPDATE step = VALUES(step), payload = VALUES(payload), updated_at = NOW()',
            [
                'tid' => $telegramId,
                'step' => $step,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    public static function clear(int $telegramId): void
    {
        Database::execute(
            'INSERT INTO user_states (telegram_id, step, payload, updated_at)
             VALUES (:tid, NULL, NULL, NOW())
             ON DUPLICATE KEY UPDATE step = NULL, payload = NULL, updated_at = NOW()',
            ['tid' => $telegramId]
        );
    }
}
