<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

final class ProviderSyncService
{
    /**
     * Fetch services from a provider API.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fetchServices(int $providerId): array
    {
        $provider = Database::fetchOne('SELECT * FROM providers WHERE id = :id', ['id' => $providerId]);
        if ($provider === null) {
            throw new ProviderException("Provider #{$providerId} topilmadi.");
        }

        $client = new ProviderClient((string) $provider['api_url'], (string) $provider['api_key']);
        $raw = $client->services();

        if (!is_array($raw)) {
            throw new ProviderException("Provayder javobi noto'g'ri formatda.");
        }

        return $raw;
    }

    /**
     * Import or synchronize services from a provider.
     *
     * @return array{total: int, created: int, updated: int}
     */
    public static function syncServicesFromProvider(int $providerId): array
    {
        $provider = Database::fetchOne('SELECT * FROM providers WHERE id = :id', ['id' => $providerId]);
        if ($provider === null) {
            throw new ProviderException("Provider #{$providerId} topilmadi.");
        }

        $markupPercent = (float) AdminService::getSetting('markup_percent', 40);
        $usdRate = (float) AdminService::getSetting('usd_to_local_rate', 12700);
        $providerCurrency = strtoupper((string) ($provider['currency'] ?? 'USD'));

        $rawServices = self::fetchServices($providerId);
        $created = 0;
        $updated = 0;

        foreach ($rawServices as $s) {
            if (!is_array($s)) {
                continue;
            }

            $providerServiceId = (string) ($s['service'] ?? $s['id'] ?? '');
            $name = trim((string) ($s['name'] ?? ''));
            $catName = trim((string) ($s['category'] ?? 'General'));
            $rate = (float) ($s['rate'] ?? 0);
            $min = max(1, (int) ($s['min'] ?? 1));
            $max = max($min, (int) ($s['max'] ?? 1000));
            $type = strtolower((string) ($s['type'] ?? 'default'));

            if ($providerServiceId === '' || $name === '') {
                continue;
            }

            // Determine selling price in UZS
            $multiplier = 1 + ($markupPercent / 100.0);
            if ($providerCurrency === 'USD') {
                $pricePer1000 = round($rate * $usdRate * $multiplier, 2);
            } else {
                $pricePer1000 = round($rate * $multiplier, 2);
            }

            // Determine Main Category (Platform) and Subcategory
            [$mainPlatform, $subName] = self::detectPlatformAndSubcategory($catName, $name);

            $categoryId = self::getOrCreateCategory($mainPlatform);
            $subcategoryId = self::getOrCreateSubcategory($categoryId, $subName);

            $orderType = str_contains($type, 'poll') || str_contains(strtolower($name), 'poll') ? 'poll' : 'default';
            $linkType = str_contains(strtolower($name), 'username') || str_contains(strtolower($catName), 'username') ? 'username' : 'url';

            $existing = Database::fetchOne(
                'SELECT id FROM services WHERE provider_id = :pid AND provider_service_id = :psid',
                ['pid' => $providerId, 'psid' => $providerServiceId]
            );

            if ($existing !== null) {
                Database::execute(
                    'UPDATE services SET
                        subcategory_id = :sub_id,
                        name_uz = :name_uz,
                        name_ru = :name_ru,
                        name_en = :name_en,
                        rate_per_1000 = :rate,
                        price_per_1000 = :price,
                        min_quantity = :min,
                        max_quantity = :max,
                        order_type = :order_type,
                        link_type = :link_type,
                        is_active = 1,
                        updated_at = NOW()
                     WHERE id = :id',
                    [
                        'sub_id' => $subcategoryId,
                        'name_uz' => $name,
                        'name_ru' => $name,
                        'name_en' => $name,
                        'rate' => $rate,
                        'price' => $pricePer1000,
                        'min' => $min,
                        'max' => $max,
                        'order_type' => $orderType,
                        'link_type' => $linkType,
                        'id' => $existing['id'],
                    ]
                );
                $updated++;
            } else {
                Database::execute(
                    'INSERT INTO services (
                        subcategory_id, provider_id, provider_service_id,
                        name_uz, name_ru, name_en,
                        order_type, link_type,
                        rate_per_1000, price_per_1000,
                        min_quantity, max_quantity,
                        auto_sync_price, is_active
                    ) VALUES (
                        :sub_id, :pid, :psid,
                        :name_uz, :name_ru, :name_en,
                        :order_type, :link_type,
                        :rate, :price,
                        :min, :max,
                        1, 1
                    )',
                    [
                        'sub_id' => $subcategoryId,
                        'pid' => $providerId,
                        'psid' => $providerServiceId,
                        'name_uz' => $name,
                        'name_ru' => $name,
                        'name_en' => $name,
                        'order_type' => $orderType,
                        'link_type' => $linkType,
                        'rate' => $rate,
                        'price' => $pricePer1000,
                        'min' => $min,
                        'max' => $max,
                    ]
                );
                $created++;
            }
        }

        // Automatically match backup failover services with other providers
        self::autoMatchBackups();

        return [
            'total' => count($rawServices),
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * Automatically match backup (failover) provider services.
     * When provider A has a service, find a matching service from provider B
     * so that if provider A fails, the order automatically falls back to provider B.
     */
    public static function autoMatchBackups(): int
    {
        $providers = Database::fetchAll('SELECT id FROM providers WHERE is_active = 1');
        if (count($providers) < 2) {
            return 0; // Need at least 2 active providers for failover
        }

        $allServices = Database::fetchAll('SELECT s.*, sub.category_id FROM services s JOIN subcategories sub ON sub.id = s.subcategory_id WHERE s.is_active = 1');
        $matchedCount = 0;

        foreach ($allServices as $svc) {
            if (!empty($svc['backup_provider_id']) && !empty($svc['backup_service_id'])) {
                continue; // Already has a backup configured
            }

            // Look for a similar service from another provider
            $candidate = Database::fetchOne(
                'SELECT s.provider_id, s.provider_service_id
                 FROM services s
                 JOIN subcategories sub ON sub.id = s.subcategory_id
                 WHERE s.provider_id != :current_pid
                   AND s.is_active = 1
                   AND sub.category_id = :cat_id
                 ORDER BY (s.subcategory_id = :sub_id) DESC, s.id ASC
                 LIMIT 1',
                [
                    'current_pid' => $svc['provider_id'],
                    'cat_id' => $svc['category_id'],
                    'sub_id' => $svc['subcategory_id'],
                ]
            );

            if ($candidate !== null) {
                Database::execute(
                    'UPDATE services SET backup_provider_id = :bpid, backup_service_id = :bsid WHERE id = :id',
                    [
                        'bpid' => $candidate['provider_id'],
                        'bsid' => $candidate['provider_service_id'],
                        'id' => $svc['id'],
                    ]
                );
                $matchedCount++;
            }
        }

        return $matchedCount;
    }

    /**
     * Helper to detect platform and subcategory title.
     *
     * @return array{0: string, 1: string}
     */
    private static function detectPlatformAndSubcategory(string $catName, string $serviceName): array
    {
        $haystack = strtolower($catName . ' ' . $serviceName);

        $platforms = [
            'instagram' => 'Instagram',
            'telegram' => 'Telegram',
            'tiktok' => 'TikTok',
            'tik tok' => 'TikTok',
            'youtube' => 'YouTube',
            'facebook' => 'Facebook',
            'twitter' => 'Twitter / X',
            'threads' => 'Threads',
            'vkontakte' => 'VK',
            'vk' => 'VK',
            'discord' => 'Discord',
            'spotify' => 'Spotify',
            'twitch' => 'Twitch',
        ];

        $platform = 'Boshqa';
        foreach ($platforms as $needle => $name) {
            if (str_contains($haystack, $needle)) {
                $platform = $name;
                break;
            }
        }

        // Subcategory name is the provider category (cleaned up)
        $sub = $catName;
        if (trim($sub) === '' || $sub === 'General') {
            $sub = $platform . ' Xizmatlari';
        }

        return [$platform, $sub];
    }

    private static function getOrCreateCategory(string $name): int
    {
        $existing = Database::fetchOne('SELECT id FROM categories WHERE name_uz = :name LIMIT 1', ['name' => $name]);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        Database::execute(
            'INSERT INTO categories (name_uz, name_ru, name_en, is_active) VALUES (:u, :r, :e, 1)',
            ['u' => $name, 'r' => $name, 'e' => $name]
        );

        return (int) Database::lastInsertId();
    }

    private static function getOrCreateSubcategory(int $categoryId, string $name): int
    {
        $existing = Database::fetchOne(
            'SELECT id FROM subcategories WHERE category_id = :cid AND name_uz = :name LIMIT 1',
            ['cid' => $categoryId, 'name' => $name]
        );
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        Database::execute(
            'INSERT INTO subcategories (category_id, name_uz, name_ru, name_en, is_active) VALUES (:cid, :u, :r, :e, 1)',
            ['cid' => $categoryId, 'u' => $name, 'r' => $name, 'e' => $name]
        );

        return (int) Database::lastInsertId();
    }
}
