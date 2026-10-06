<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class UserService
{
    public static function findOrCreateByTelegramId(int $telegramId, ?string $username, ?string $firstName): array
    {
        $user = Database::fetchOne('SELECT * FROM users WHERE telegram_id = :tid', ['tid' => $telegramId]);

        if ($user !== null) {
            if ($user['username'] !== $username || $user['first_name'] !== $firstName) {
                Database::execute(
                    'UPDATE users SET username = :username, first_name = :first_name WHERE id = :id',
                    ['username' => $username, 'first_name' => $firstName, 'id' => $user['id']]
                );
                $user['username'] = $username;
                $user['first_name'] = $firstName;
            }

            return $user;
        }

        Database::execute(
            'INSERT INTO users (telegram_id, username, first_name) VALUES (:tid, :username, :first_name)',
            ['tid' => $telegramId, 'username' => $username, 'first_name' => $firstName]
        );

        return Database::fetchOne('SELECT * FROM users WHERE telegram_id = :tid', ['tid' => $telegramId]);
    }

    public static function findByTelegramId(int $telegramId): ?array
    {
        return Database::fetchOne('SELECT * FROM users WHERE telegram_id = :tid', ['tid' => $telegramId]);
    }

    public static function find(int $userId): ?array
    {
        return Database::fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
    }

    public static function setLanguage(int $userId, string $locale): void
    {
        Database::execute('UPDATE users SET language = :lang WHERE id = :id', ['lang' => $locale, 'id' => $userId]);
    }

    public static function touchLastMessage(int $userId): void
    {
        Database::execute('UPDATE users SET last_message_at = NOW() WHERE id = :id', ['id' => $userId]);
    }

    /**
     * Compared entirely in SQL (NOW() vs last_message_at, both computed by
     * MySQL) rather than via PHP's strtotime()/time(), which run under
     * PHP's configured timezone and can disagree with the DB server's by
     * several hours — see the matching note on SessionState::get().
     */
    public static function isFloodLimited(int $userId, int $antifloodSeconds): bool
    {
        if ($antifloodSeconds <= 0) {
            return false;
        }

        $seconds = max(0, $antifloodSeconds);
        $row = Database::fetchOne(
            'SELECT 1 FROM users
             WHERE id = :id AND last_message_at IS NOT NULL
               AND last_message_at >= NOW() - INTERVAL ' . $seconds . ' SECOND',
            ['id' => $userId]
        );

        return $row !== null;
    }

    public static function setBanned(int $userId, bool $banned): void
    {
        Database::execute('UPDATE users SET is_banned = :b WHERE id = :id', ['b' => $banned ? 1 : 0, 'id' => $userId]);
    }

    public static function incrementOrderStats(int $userId): void
    {
        Database::execute('UPDATE users SET order_count = order_count + 1 WHERE id = :id', ['id' => $userId]);
    }

    public static function countAll(): int
    {
        return (int) (Database::fetchOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
    }

    public static function sumBalances(): float
    {
        return (float) (Database::fetchOne('SELECT COALESCE(SUM(balance),0) AS s FROM users')['s'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function allTelegramIdsBatch(int $afterId, int $limit): array
    {
        // LIMIT is interpolated (not bound) because PDO with EMULATE_PREPARES=false
        // cannot bind LIMIT as a string parameter; safe here since $limit is cast to int.
        $limit = (int) $limit;

        return Database::fetchAll(
            "SELECT id, telegram_id FROM users WHERE id > :after AND is_banned = 0 ORDER BY id ASC LIMIT {$limit}",
            ['after' => $afterId]
        );
    }
}
