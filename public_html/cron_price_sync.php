<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Services\AdminService;
use App\Services\CatalogService;
use App\Services\ProviderClient;

if (PHP_SAPI !== 'cli') {
    if (!hash_equals((string) Config::get('cron_token'), (string) ($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!Database::tryLock('smm_price_sync', 0)) {
    echo "Another price-sync run is already in progress, skipping.\n";
    exit;
}

register_shutdown_function(static function (): void {
    Database::releaseLock('smm_price_sync');
});

$services = CatalogService::allActiveServicesWithAutoSync();
if ($services === []) {
    echo "No active services configured for auto price sync.\n";
    exit;
}

$usdRate = (float) AdminService::getSetting('usd_to_local_rate', 12700);
$markup = (float) AdminService::getSetting('markup_percent', 40);

$providerCache = [];
$providerServicesCache = [];
$updated = 0;
$unchanged = 0;
$errors = 0;

foreach ($services as $service) {
    $providerId = (int) $service['provider_id'];

    if (!isset($providerCache[$providerId])) {
        $provider = Database::fetchOne('SELECT * FROM providers WHERE id = :id', ['id' => $providerId]);
        $providerCache[$providerId] = $provider;

        if ($provider !== null && (int) $provider['is_active'] === 1) {
            try {
                $client = new ProviderClient($provider['api_url'], $provider['api_key']);
                $rawList = $client->services();
                $mapped = [];
                if (is_array($rawList)) {
                    foreach ($rawList as $item) {
                        if (isset($item['service'])) {
                            $mapped[(string) $item['service']] = $item;
                        }
                    }
                }
                $providerServicesCache[$providerId] = $mapped;
            } catch (\Throwable $e) {
                Logger::warning("Could not fetch service list for provider #{$providerId}: " . $e->getMessage());
                $providerServicesCache[$providerId] = null;
            }
        } else {
            $providerServicesCache[$providerId] = null;
        }
    }

    $provider = $providerCache[$providerId];
    $providerServices = $providerServicesCache[$providerId];

    if ($provider === null || $providerServices === null) {
        $errors++;
        continue;
    }

    $providerSvcId = (string) $service['provider_service_id'];
    if (!isset($providerServices[$providerSvcId])) {
        $errors++;
        continue;
    }

    $upstream = $providerServices[$providerSvcId];
    $newRate = isset($upstream['rate']) && is_numeric($upstream['rate']) ? (float) $upstream['rate'] : (float) $service['rate_per_1000'];
    $newPrice = CatalogService::computeLocalPrice($newRate, (string) ($provider['currency'] ?? 'USD'), $usdRate, $markup);

    if (abs($newPrice - (float) $service['price_per_1000']) > 0.001 || abs($newRate - (float) $service['rate_per_1000']) > 0.0001) {
        Database::execute(
            'UPDATE services SET rate_per_1000 = :r, price_per_1000 = :p WHERE id = :id',
            ['r' => $newRate, 'p' => $newPrice, 'id' => $service['id']]
        );
        $updated++;
    } else {
        $unchanged++;
    }
}

echo "Price sync summary: updated={$updated}, unchanged={$unchanged}, errors={$errors}\n";
