<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AdminService
{
    public static function isAdmin(int $telegramId): bool
    {
        return self::find($telegramId) !== null;
    }

    public static function isSuperAdmin(int $telegramId): bool
    {
        $admin = self::find($telegramId);

        return $admin !== null && $admin['role'] === 'super_admin';
    }

    public static function find(int $telegramId): ?array
    {
        return Database::fetchOne('SELECT * FROM admins WHERE telegram_id = :tid', ['tid' => $telegramId]);
    }

    public static function all(): array
    {
        return Database::fetchAll('SELECT * FROM admins ORDER BY id ASC');
    }

    public static function add(int $telegramId, string $role = 'admin', ?int $addedByAdminId = null): void
    {
        Database::execute(
            'INSERT IGNORE INTO admins (telegram_id, role, added_by_admin_id) VALUES (:tid, :role, :by)',
            ['tid' => $telegramId, 'role' => $role, 'by' => $addedByAdminId]
        );
    }

    public static function remove(int $telegramId): void
    {
        Database::execute('DELETE FROM admins WHERE telegram_id = :tid AND role <> :super', [
            'tid' => $telegramId,
            'super' => 'super_admin',
        ]);
    }

    public static function getSetting(string $key, mixed $default = null): mixed
    {
        $row = Database::fetchOne('SELECT value, type FROM settings WHERE `key` = :k', ['k' => $key]);

        if ($row === null) {
            return $default;
        }

        return match ($row['type']) {
            'int' => (int) $row['value'],
            'decimal' => (float) $row['value'],
            'bool' => $row['value'] === '1',
            'json' => json_decode((string) $row['value'], true),
            default => $row['value'],
        };
    }

    public static function setSetting(string $key, mixed $value, string $type = 'string'): void
    {
        $stored = match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };

        Database::execute(
            'INSERT INTO settings (`key`, value, type) VALUES (:k, :v, :t)
             ON DUPLICATE KEY UPDATE value = VALUES(value), type = VALUES(type)',
            ['k' => $key, 'v' => $stored, 't' => $type]
        );
    }
}
