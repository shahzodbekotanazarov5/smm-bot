<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TelegramApi;
use App\Services\AdminService;
use App\Services\I18nService;
use App\Services\OrderService;
use App\Services\ProviderClient;
use App\Services\UserService;

if (PHP_SAPI !== 'cli') {
    if (!hash_equals((string) Config::get('cron_token'), (string) ($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!Database::tryLock('smm_status_sync', 0)) {
    echo "Another status-sync run is already in progress, skipping.\n";
    exit;
}

register_shutdown_function(static function (): void {
    Database::releaseLock('smm_status_sync');
});

function notifyOrderUser(array $order, string $key, array $vars): void
{
    $user = UserService::find((int) $order['user_id']);
    if ($user === null) {
        return;
    }

    $locale = $user['language'] ?? (string) Config::get('default_locale', 'uz');
    TelegramApi::sendMessage((int) $user['telegram_id'], I18nService::t($key, $vars, $locale));
}

$summary = ['completed' => 0, 'canceled' => 0, 'partial' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
$currency = (string) AdminService::getSetting('currency_label', "so'm");

$orders = OrderService::claimBatch(200);
$providerClients = [];

foreach ($orders as $order) {
    $providerId = (int) $order['provider_id'];

    if (!array_key_exists($providerId, $providerClients)) {
        $provider = Database::fetchOne('SELECT * FROM providers WHERE id = :id', ['id' => $providerId]);
        $providerClients[$providerId] = ($provider !== null && (int) $provider['is_active'] === 1)
            ? new ProviderClient($provider['api_url'], $provider['api_key'])
            : null;
    }

    $client = $providerClients[$providerId];
    if ($client === null) {
        continue;
    }

    try {
        $result = OrderService::syncOne($order, $client);

        match ($result['result']) {
            'completed' => notifyOrderUser($order, 'order.notify_completed', ['id' => $order['id']]),
            'canceled' => notifyOrderUser($order, 'order.notify_canceled', [
                'id' => $order['id'],
                'amount' => number_format((float) ($result['amount'] ?? 0), 2),
                'currency' => $currency,
            ]),
            'partial' => notifyOrderUser($order, 'order.notify_partial', [
                'id' => $order['id'],
                'amount' => number_format((float) ($result['amount'] ?? 0), 2),
                'currency' => $currency,
            ]),
            default => null,
        };

        $bucket = match ($result['result']) {
            'completed', 'canceled', 'partial', 'skipped' => $result['result'],
            'status_updated', 'unchanged' => 'updated',
            default => null,
        };

        if ($bucket !== null) {
            $summary[$bucket]++;
        }
    } catch (\Throwable $e) {
        $summary['errors']++;
        Logger::error("Status sync failed for order {$order['id']}: " . $e->getMessage());
    }
}

echo 'Status sync summary: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";
