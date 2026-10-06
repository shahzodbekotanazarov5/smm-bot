<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class TicketService
{
    public static function findOpenOrAnswered(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM tickets WHERE user_id = :uid AND status IN ('open','answered') ORDER BY id DESC LIMIT 1",
            ['uid' => $userId]
        );
    }

    /**
     * Most recent ticket regardless of status, used only when the user
     * explicitly re-opens support (button/command) — so a closed ticket's
     * history is reused (addUserMessage reopens it) instead of forking a
     * new thread. NOT used for the free-text fallback route, which should
     * only catch messages while a ticket is actively open/answered.
     */
    public static function findLatest(int $userId): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM tickets WHERE user_id = :uid ORDER BY id DESC LIMIT 1',
            ['uid' => $userId]
        );
    }

    public static function createTicket(int $userId, string $message, ?int $telegramMessageId = null): array
    {
        return Database::transaction(function () use ($userId, $message, $telegramMessageId) {
            Database::execute('INSERT INTO tickets (user_id, status) VALUES (:uid, :status)', [
                'uid' => $userId,
                'status' => 'open',
            ]);
            $ticketId = Database::lastInsertId();

            self::appendMessage($ticketId, 'user', $userId, $message, $telegramMessageId);

            return Database::fetchOne('SELECT * FROM tickets WHERE id = :id', ['id' => $ticketId]);
        });
    }

    public static function addUserMessage(int $ticketId, int $userTelegramId, string $message, ?int $telegramMessageId = null): void
    {
        self::appendMessage($ticketId, 'user', $userTelegramId, $message, $telegramMessageId);

        // A user replying reopens a closed ticket into "open" so it's not missed.
        Database::execute(
            "UPDATE tickets SET status = IF(status = 'closed', 'open', status), updated_at = NOW() WHERE id = :id",
            ['id' => $ticketId]
        );
    }

    public static function addAdminReply(int $ticketId, int $adminTelegramId, string $message): void
    {
        self::appendMessage($ticketId, 'admin', $adminTelegramId, $message);
        Database::execute("UPDATE tickets SET status = 'answered' WHERE id = :id", ['id' => $ticketId]);
    }

    public static function close(int $ticketId): void
    {
        Database::execute("UPDATE tickets SET status = 'closed', closed_at = NOW() WHERE id = :id", ['id' => $ticketId]);
    }

    public static function find(int $ticketId): ?array
    {
        return Database::fetchOne('SELECT * FROM tickets WHERE id = :id', ['id' => $ticketId]);
    }

    public static function messages(int $ticketId): array
    {
        return Database::fetchAll(
            'SELECT * FROM ticket_messages WHERE ticket_id = :id ORDER BY id ASC',
            ['id' => $ticketId]
        );
    }

    public static function listPage(int $page, int $perPage = 8): array
    {
        $perPage = (int) $perPage;
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::fetchOne('SELECT COUNT(*) AS c FROM tickets')['c'];

        $rows = Database::fetchAll(
            "SELECT t.*, u.telegram_id, u.username FROM tickets t
             JOIN users u ON u.id = t.user_id
             ORDER BY (t.status = 'open') DESC, (t.status = 'answered') DESC, t.updated_at ASC
             LIMIT {$perPage} OFFSET {$offset}"
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    private static function appendMessage(int $ticketId, string $senderType, int $senderId, string $message, ?int $telegramMessageId = null): void
    {
        Database::execute(
            'INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, message, telegram_message_id)
             VALUES (:tid, :type, :sender, :message, :tmid)',
            [
                'tid' => $ticketId,
                'type' => $senderType,
                'sender' => $senderId,
                'message' => $message,
                'tmid' => $telegramMessageId,
            ]
        );
    }
}
