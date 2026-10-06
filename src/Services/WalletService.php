<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * The only code path allowed to mutate users.balance. Every mutation is an
 * atomic, transaction-wrapped UPDATE with a WHERE guard (never a stale
 * read-then-write), and every mutation is recorded in wallet_transactions.
 */
final class WalletService
{
    public static function credit(
        int $userId,
        float $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminId = null,
        ?string $note = null
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        return Database::transaction(function () use ($userId, $amount, $reason, $referenceType, $referenceId, $adminId, $note) {
            Database::execute(
                'UPDATE users SET balance = balance + :amt, total_topup = IF(:isTopup, total_topup + :amt2, total_topup) WHERE id = :id',
                [
                    'amt' => $amount,
                    'amt2' => $amount,
                    // 'admin_adjust' counts toward total_topup too: until a real payment
                    // gateway is wired in, admin-reviewed manual credits ARE the top-up path.
                    'isTopup' => in_array($reason, ['topup', 'admin_adjust'], true) ? 1 : 0,
                    'id' => $userId,
                ]
            );

            $balance = (float) Database::fetchOne('SELECT balance FROM users WHERE id = :id', ['id' => $userId])['balance'];

            $txId = self::recordTransaction($userId, 'credit', $amount, $balance, $reason, $referenceType, $referenceId, $adminId, $note);

            return ['balance' => $balance, 'transaction_id' => $txId];
        });
    }

    /**
     * @throws InsufficientBalanceException
     */
    public static function debit(
        int $userId,
        float $amount,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminId = null,
        ?string $note = null
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }

        return Database::transaction(function () use ($userId, $amount, $reason, $referenceType, $referenceId, $adminId, $note) {
            $affected = Database::execute(
                'UPDATE users SET balance = balance - :amt, total_spent = total_spent + :amt2
                 WHERE id = :id AND balance >= :amt3',
                ['amt' => $amount, 'amt2' => $amount, 'amt3' => $amount, 'id' => $userId]
            );

            if ($affected !== 1) {
                throw new InsufficientBalanceException("User {$userId} has insufficient balance for a debit of {$amount}.");
            }

            $balance = (float) Database::fetchOne('SELECT balance FROM users WHERE id = :id', ['id' => $userId])['balance'];

            $txId = self::recordTransaction($userId, 'debit', $amount, $balance, $reason, $referenceType, $referenceId, $adminId, $note);

            return ['balance' => $balance, 'transaction_id' => $txId];
        });
    }

    public static function adjustByAdmin(int $userId, float $delta, int $adminId, ?string $note = null): array
    {
        return $delta >= 0
            ? self::credit($userId, $delta, 'admin_adjust', 'admin', $adminId, $adminId, $note)
            : self::debit($userId, abs($delta), 'admin_adjust', 'admin', $adminId, $adminId, $note);
    }

    public static function refundOrder(int $userId, int $orderId, float $amount, ?string $note = null): array
    {
        return self::credit($userId, $amount, 'order_refund', 'order', $orderId, null, $note);
    }

    private static function recordTransaction(
        int $userId,
        string $type,
        float $amount,
        float $balanceAfter,
        string $reason,
        ?string $referenceType,
        ?int $referenceId,
        ?int $adminId,
        ?string $note
    ): int {
        Database::execute(
            'INSERT INTO wallet_transactions (user_id, type, amount, balance_after, reason, reference_type, reference_id, admin_id, note)
             VALUES (:user_id, :type, :amount, :balance_after, :reason, :reference_type, :reference_id, :admin_id, :note)',
            [
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'admin_id' => $adminId,
                'note' => $note,
            ]
        );

        return Database::lastInsertId();
    }
}
