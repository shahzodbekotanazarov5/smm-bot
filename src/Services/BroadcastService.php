<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TelegramApi;

final class BroadcastService
{
    public static function create(int $adminId, string $message): int
    {
        $total = UserService::countAll();

        Database::execute(
            'INSERT INTO broadcasts (admin_id, message, status, total_recipients) VALUES (:aid, :msg, :status, :total)',
            ['aid' => $adminId, 'msg' => $message, 'status' => 'pending', 'total' => $total]
        );

        return Database::lastInsertId();
    }

    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM broadcasts WHERE id = :id', ['id' => $id]);
    }

    public static function pendingOrRunning(): array
    {
        return Database::fetchAll("SELECT * FROM broadcasts WHERE status IN ('pending','running') ORDER BY id ASC");
    }

    /**
     * Sends up to $limit messages, resuming from the stored cursor. Safe to
     * call repeatedly — once from the admin handler (first chunk, for
     * instant feedback on small panels) and then from cron_broadcast.php for
     * the rest, so large recipient lists never risk a PHP execution timeout.
     *
     * @return array{sent:int, done:bool}
     */
    public static function processBatch(int $broadcastId, int $limit = 150): array
    {
        $broadcast = self::find($broadcastId);

        if ($broadcast === null || in_array($broadcast['status'], ['done', 'canceled'], true)) {
            return ['sent' => 0, 'done' => true];
        }

        Database::execute(
            "UPDATE broadcasts SET status = 'running', started_at = COALESCE(started_at, NOW()) WHERE id = :id",
            ['id' => $broadcastId]
        );

        $users = UserService::allTelegramIdsBatch((int) $broadcast['last_user_id_cursor'], $limit);
        $sent = 0;
        $lastId = (int) $broadcast['last_user_id_cursor'];

        foreach ($users as $u) {
            TelegramApi::sendMessage((int) $u['telegram_id'], (string) $broadcast['message']);
            $sent++;
            $lastId = (int) $u['id'];
            usleep(40000); // ~25 msg/sec, safely under Telegram's global rate limit
        }

        $done = count($users) < $limit;

        Database::execute(
            'UPDATE broadcasts SET sent_count = sent_count + :sent, last_user_id_cursor = :cursor,
                status = :status, finished_at = :finished
             WHERE id = :id',
            [
                'sent' => $sent,
                'cursor' => $lastId,
                'status' => $done ? 'done' : 'running',
                'finished' => $done ? date('Y-m-d H:i:s') : null,
                'id' => $broadcastId,
            ]
        );

        return ['sent' => $sent, 'done' => $done];
    }
}
