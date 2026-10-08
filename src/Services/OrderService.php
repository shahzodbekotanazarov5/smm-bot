<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Support\Validator;

final class OrderService
{
    private const TERMINAL_STATUSES = ['completed', 'canceled', 'failed', 'refunded'];

    public static function calculatePrice(array $service, int $quantity): float
    {
        return round(((float) $service['price_per_1000']) * $quantity / 1000, 2);
    }

    public static function validateQuantity(array $service, mixed $input): int|false
    {
        return Validator::quantity($input, (int) $service['min_quantity'], (int) $service['max_quantity']);
    }

    public static function validateLink(array $service, string $input): string|false
    {
        return Validator::link($input, (string) $service['link_type']);
    }

    public static function loadServiceWithProvider(int $serviceId): ?array
    {
        return Database::fetchOne(
            'SELECT s.*, p.api_url, p.api_key, p.is_active AS provider_active,
                    bp.api_url AS backup_api_url, bp.api_key AS backup_api_key, bp.is_active AS backup_provider_active
             FROM services s
             JOIN providers p ON p.id = s.provider_id
             LEFT JOIN providers bp ON bp.id = s.backup_provider_id
             WHERE s.id = :id',
            ['id' => $serviceId]
        );
    }

    /**
     * Places an order end to end: re-validates everything at commit time (not
     * just when the FSM step first ran), atomically debits the wallet and
     * inserts a pending order in one short transaction, THEN calls the
     * provider outside any open transaction. On provider failure the charge
     * is fully refunded and the order marked failed (compensating action —
     * we never hold a DB lock across the network call).
     *
     * @throws InsufficientBalanceException|ProviderException|\RuntimeException|\InvalidArgumentException
     */
    public static function placeOrder(int $userId, int $serviceId, int $quantity, string $target, ?int $pollAnswer = null): array
    {
        $service = self::loadServiceWithProvider($serviceId);

        if ($service === null || (int) $service['is_active'] !== 1 || (int) $service['provider_active'] !== 1) {
            throw new \RuntimeException('Service unavailable.');
        }

        if (self::validateQuantity($service, $quantity) === false) {
            throw new \InvalidArgumentException('Invalid quantity.');
        }

        if (self::validateLink($service, $target) === false) {
            throw new \InvalidArgumentException('Invalid link.');
        }

        $price = self::calculatePrice($service, $quantity);
        $extra = $pollAnswer !== null ? json_encode(['answer_number' => $pollAnswer]) : null;

        $orderId = Database::transaction(function () use ($userId, $service, $price, $target, $quantity, $extra) {
            WalletService::debit($userId, $price, 'order_charge');

            Database::execute(
                'INSERT INTO orders (
                    user_id, service_id, service_name_snapshot, price_per_1000_snapshot, provider_id,
                    target_link, quantity, charge_amount, status, extra_params
                 ) VALUES (
                    :user_id, :service_id, :name, :price_snap, :provider_id,
                    :target, :quantity, :charge, :status, :extra
                 )',
                [
                    'user_id' => $userId,
                    'service_id' => $service['id'],
                    'name' => $service['name_uz'],
                    'price_snap' => $service['price_per_1000'],
                    'provider_id' => $service['provider_id'],
                    'target' => $target,
                    'quantity' => $quantity,
                    'charge' => $price,
                    'status' => 'pending',
                    'extra' => $extra,
                ]
            );

            return Database::lastInsertId();
        });

        $client = new ProviderClient((string) $service['api_url'], (string) $service['api_key']);

        // Safety net: the balance was already debited above. If the process
        // is killed outright while the provider call below is in flight
        // (e.g. PHP's max_execution_time is hit — a fatal that bypasses the
        // catch block below entirely), this still runs on shutdown and
        // refunds the charge instead of silently leaving it debited with a
        // stuck 'pending' order. It's a no-op once the order reaches any
        // non-pending status, which both the success path and the catch
        // block below set before this could ever double-refund.
        register_shutdown_function(static function () use ($orderId, $userId, $price): void {
            $order = Database::fetchOne('SELECT status FROM orders WHERE id = :id', ['id' => $orderId]);
            if ($order === null || $order['status'] !== 'pending') {
                return;
            }

            try {
                WalletService::refundOrder($userId, $orderId, $price, 'Refunded after unexpected termination during provider call');
                Database::execute(
                    'UPDATE orders SET status = :status, refunded_amount = :amt WHERE id = :id',
                    ['status' => 'failed', 'amt' => $price, 'id' => $orderId]
                );
            } catch (\Throwable $e) {
                Logger::error("Shutdown-time refund failed for order {$orderId}: " . $e->getMessage());
            }
        });

        $params = [
            'service' => $service['provider_service_id'],
            'link' => $target,
            'quantity' => $quantity,
        ];
        if ($pollAnswer !== null) {
            $params['answer_number'] = $pollAnswer;
        }

        try {
            $result = $client->add($params);

            if (empty($result['order'])) {
                throw new ProviderException('Provider did not return an order id.');
            }

            Database::execute(
                'UPDATE orders SET provider_order_id = :poid, status = :status WHERE id = :id',
                ['poid' => (string) $result['order'], 'status' => 'in_progress', 'id' => $orderId]
            );
        } catch (ProviderException $primaryException) {
            Logger::warning("Primary provider #{$service['provider_id']} failed for order #{$orderId}: " . $primaryException->getMessage());

            $failoverSuccess = false;
            if (!empty($service['backup_provider_id']) && !empty($service['backup_service_id']) && !empty($service['backup_api_url']) && (int) ($service['backup_provider_active'] ?? 0) === 1) {
                try {
                    Logger::info("Attempting failover for order #{$orderId} to backup provider #{$service['backup_provider_id']}");
                    $backupClient = new ProviderClient((string) $service['backup_api_url'], (string) $service['backup_api_key']);
                    $backupParams = $params;
                    $backupParams['service'] = $service['backup_service_id'];

                    $backupResult = $backupClient->add($backupParams);
                    if (!empty($backupResult['order'])) {
                        Database::execute(
                            'UPDATE orders SET provider_id = :pid, provider_order_id = :poid, status = :status WHERE id = :id',
                            [
                                'pid' => $service['backup_provider_id'],
                                'poid' => (string) $backupResult['order'],
                                'status' => 'in_progress',
                                'id' => $orderId,
                            ]
                        );
                        $failoverSuccess = true;
                        Logger::info("Failover succeeded for order #{$orderId}! Executed on backup provider #{$service['backup_provider_id']} (order {$backupResult['order']})");
                    }
                } catch (\Throwable $backupException) {
                    Logger::error("Backup provider #{$service['backup_provider_id']} also failed for order #{$orderId}: " . $backupException->getMessage());
                }
            }

            if (!$failoverSuccess) {
                WalletService::refundOrder($userId, $orderId, $price, 'Order placement failed: ' . $primaryException->getMessage());
                Database::execute(
                    'UPDATE orders SET status = :status, refunded_amount = :amt WHERE id = :id',
                    ['status' => 'failed', 'amt' => $price, 'id' => $orderId]
                );

                throw $primaryException;
            }
        }

        UserService::incrementOrderStats($userId);

        return Database::fetchOne('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
    }

    public static function historyPage(int $userId, int $page, int $perPage = 5): array
    {
        $perPage = max(1, (int) $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) Database::fetchOne('SELECT COUNT(*) AS c FROM orders WHERE user_id = :uid', ['uid' => $userId])['c'];

        $rows = Database::fetchAll(
            "SELECT * FROM orders WHERE user_id = :uid ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            ['uid' => $userId]
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public static function find(int $orderId): ?array
    {
        return Database::fetchOne('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
    }

    public static function countAll(): int
    {
        return (int) (Database::fetchOne('SELECT COUNT(*) AS c FROM orders')['c'] ?? 0);
    }

    // --- Status sync, called by public_html/cron_status_sync.php -----------

    public static function claimBatch(int $limit = 200): array
    {
        $limit = max(1, $limit);

        return Database::fetchAll(
            "SELECT * FROM orders
             WHERE status IN ('pending','in_progress','processing') AND provider_order_id IS NOT NULL
             ORDER BY updated_at ASC LIMIT {$limit}"
        );
    }

    private static function mapProviderStatus(string $raw): string
    {
        $normalized = strtolower(trim($raw));

        return match (true) {
            str_contains($normalized, 'partial') => 'partial',
            str_contains($normalized, 'cancel') => 'canceled',
            str_contains($normalized, 'complete') => 'completed',
            str_contains($normalized, 'process') => 'processing',
            str_contains($normalized, 'progress') => 'in_progress',
            str_contains($normalized, 'pending') => 'pending',
            default => 'in_progress',
        };
    }

    /**
     * Syncs one order against the provider and applies the correct refund
     * branch. completed / canceled / partial are SIBLING branches (this is
     * the direct fix for the old codebase's bug where the cancel-refund
     * logic was nested inside the completed branch and could never run).
     * Runs in its own short transaction so one bad order can't roll back
     * the rest of the cron's batch.
     */
    public static function syncOne(array $order, ProviderClient $client): array
    {
        $result = $client->status((string) $order['provider_order_id']);
        $rawStatus = (string) ($result['status'] ?? '');

        if ($rawStatus === '') {
            return ['order_id' => $order['id'], 'result' => 'no_status'];
        }

        $newStatus = self::mapProviderStatus($rawStatus);
        $startCount = isset($result['start_count']) && is_numeric($result['start_count']) ? (int) $result['start_count'] : null;
        $remains = isset($result['remains']) && is_numeric($result['remains']) ? (int) $result['remains'] : null;

        return Database::transaction(function () use ($order, $newStatus, $startCount, $remains) {
            $current = Database::fetchOne('SELECT * FROM orders WHERE id = :id FOR UPDATE', ['id' => $order['id']]);

            if ($current === null || in_array($current['status'], self::TERMINAL_STATUSES, true)) {
                return ['order_id' => $order['id'], 'result' => 'skipped'];
            }

            if ($newStatus === $current['status']) {
                Database::execute(
                    'UPDATE orders SET start_count = COALESCE(:sc, start_count), remains = COALESCE(:rm, remains) WHERE id = :id',
                    ['sc' => $startCount, 'rm' => $remains, 'id' => $order['id']]
                );

                return ['order_id' => $order['id'], 'result' => 'unchanged'];
            }

            if ($newStatus === 'completed') {
                Database::execute(
                    'UPDATE orders SET status = :s, start_count = COALESCE(:sc, start_count), remains = 0, notified_at = NOW() WHERE id = :id',
                    ['s' => 'completed', 'sc' => $startCount, 'id' => $order['id']]
                );

                return ['order_id' => $order['id'], 'result' => 'completed', 'user_id' => (int) $current['user_id']];
            }

            if ($newStatus === 'canceled') {
                $refundAmount = round((float) $current['charge_amount'] - (float) $current['refunded_amount'], 4);

                if ($refundAmount > 0) {
                    WalletService::refundOrder((int) $current['user_id'], (int) $current['id'], $refundAmount, 'Order canceled by provider');
                }

                Database::execute(
                    'UPDATE orders SET status = :s, refunded_amount = charge_amount, notified_at = NOW() WHERE id = :id',
                    ['s' => 'canceled', 'id' => $order['id']]
                );

                return ['order_id' => $order['id'], 'result' => 'canceled', 'user_id' => (int) $current['user_id'], 'amount' => $refundAmount];
            }

            if ($newStatus === 'partial') {
                $quantity = (int) $current['quantity'];
                $remainsVal = $remains ?? 0;
                $proratedRefund = $quantity > 0
                    ? round(((float) $current['charge_amount'] / $quantity) * $remainsVal, 4)
                    : 0.0;
                $refundAmount = max(0.0, round($proratedRefund - (float) $current['refunded_amount'], 4));

                if ($refundAmount > 0) {
                    WalletService::refundOrder((int) $current['user_id'], (int) $current['id'], $refundAmount, 'Order partially completed');
                }

                Database::execute(
                    'UPDATE orders SET status = :s, remains = :rm, refunded_amount = refunded_amount + :amt, notified_at = NOW() WHERE id = :id',
                    ['s' => 'partial', 'rm' => $remainsVal, 'amt' => $refundAmount, 'id' => $order['id']]
                );

                return ['order_id' => $order['id'], 'result' => 'partial', 'user_id' => (int) $current['user_id'], 'amount' => $refundAmount];
            }

            Database::execute(
                'UPDATE orders SET status = :s, start_count = COALESCE(:sc, start_count), remains = COALESCE(:rm, remains) WHERE id = :id',
                ['s' => $newStatus, 'sc' => $startCount, 'rm' => $remains, 'id' => $order['id']]
            );

            return ['order_id' => $order['id'], 'result' => 'status_updated'];
        });
    }
}
