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
     * Import or synchronize services from a provider with in-memory caching for high speed.
     *
     * @return array{total: int, created: int, updated: int}
     */
    public static function syncServicesFromProvider(int $providerId, int $limit = 0): array
    {
        $provider = Database::fetchOne('SELECT * FROM providers WHERE id = :id', ['id' => $providerId]);
        if ($provider === null) {
            throw new ProviderException("Provider #{$providerId} topilmadi.");
        }

        $markupPercent = (float) AdminService::getSetting('markup_percent', 40);
        $usdRate = (float) AdminService::getSetting('usd_to_local_rate', 12700);
        $providerCurrency = strtoupper((string) ($provider['currency'] ?? 'USD'));

        $rawServices = self::fetchServices($providerId);
        if ($limit > 0 && count($rawServices) > $limit) {
            $rawServices = array_slice($rawServices, 0, $limit);
        }

        // 1. In-memory cache for categories
        $catRows = Database::fetchAll('SELECT id, name_uz FROM categories');
        $categoriesMap = [];
        foreach ($catRows as $c) {
            $categoriesMap[mb_strtolower((string) $c['name_uz'])] = (int) $c['id'];
        }

        // 2. In-memory cache for subcategories
        $subRows = Database::fetchAll('SELECT id, category_id, name_uz FROM subcategories');
        $subcategoriesMap = [];
        foreach ($subRows as $s) {
            $key = $s['category_id'] . '_' . mb_strtolower((string) $s['name_uz']);
            $subcategoriesMap[$key] = (int) $s['id'];
        }

        // 3. In-memory cache for existing services belonging to this provider
        $existingRows = Database::fetchAll(
            'SELECT id, provider_service_id FROM services WHERE provider_id = :pid',
            ['pid' => $providerId]
        );
        $existingServicesMap = [];
        foreach ($existingRows as $er) {
            $existingServicesMap[(string) $er['provider_service_id']] = (int) $er['id'];
        }

        $pdo = Database::connection();
        $updateStmt = $pdo->prepare(
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
             WHERE id = :id'
        );

        $insertStmt = $pdo->prepare(
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
            )'
        );

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
            $max = max($min, (int) ($s['max'] ?? 1000000));
            $type = strtolower((string) ($s['type'] ?? 'default'));

            if ($providerServiceId === '' || $name === '') {
                continue;
            }

            // Selling price with markup in UZS
            $multiplier = 1 + ($markupPercent / 100.0);
            if ($providerCurrency === 'USD') {
                $pricePer1000 = round($rate * $usdRate * $multiplier, 2);
            } else {
                $pricePer1000 = round($rate * $multiplier, 2);
            }

            // Platform and subcategory detection
            [$mainPlatform, $subName] = self::detectPlatformAndSubcategory($catName, $name);

            $categoryId = self::getOrCreateCategoryCached($mainPlatform, $categoriesMap);
            $subcategoryId = self::getOrCreateSubcategoryCached($categoryId, $subName, $subcategoriesMap);

            $orderType = (str_contains($type, 'poll') || str_contains(strtolower($name), 'poll')) ? 'poll' : 'default';
            $linkType = (str_contains(strtolower($name), 'username') || str_contains(strtolower($catName), 'username')) ? 'username' : 'url';

            if (isset($existingServicesMap[$providerServiceId])) {
                $existingId = $existingServicesMap[$providerServiceId];
                $updateStmt->execute([
                    ':sub_id' => $subcategoryId,
                    ':name_uz' => $name,
                    ':name_ru' => $name,
                    ':name_en' => $name,
                    ':rate' => $rate,
                    ':price' => $pricePer1000,
                    ':min' => $min,
                    ':max' => $max,
                    ':order_type' => $orderType,
                    ':link_type' => $linkType,
                    ':id' => $existingId,
                ]);
                $updated++;
            } else {
                $insertStmt->execute([
                    ':sub_id' => $subcategoryId,
                    ':pid' => $providerId,
                    ':psid' => $providerServiceId,
                    ':name_uz' => $name,
                    ':name_ru' => $name,
                    ':name_en' => $name,
                    ':order_type' => $orderType,
                    ':link_type' => $linkType,
                    ':rate' => $rate,
                    ':price' => $pricePer1000,
                    ':min' => $min,
                    ':max' => $max,
                ]);
                $newId = (int) $pdo->lastInsertId();
                $existingServicesMap[$providerServiceId] = $newId;
                $created++;
            }
        }

        // Run automatic backup matching after sync
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

        $allServices = Database::fetchAll(
            'SELECT s.id, s.provider_id, s.provider_service_id, s.subcategory_id, s.backup_provider_id, sub.category_id
             FROM services s
             JOIN subcategories sub ON sub.id = s.subcategory_id
             WHERE s.is_active = 1'
        );

        // Group services in memory by category and subcategory
        $bySubcategory = [];
        $byCategory = [];

        foreach ($allServices as $svc) {
            $pid = (int) $svc['provider_id'];
            $subId = (int) $svc['subcategory_id'];
            $catId = (int) $svc['category_id'];

            $bySubcategory[$subId][$pid][] = $svc;
            $byCategory[$catId][$pid][] = $svc;
        }

        $pdo = Database::connection();
        $updateStmt = $pdo->prepare(
            'UPDATE services SET backup_provider_id = :bpid, backup_service_id = :bsid WHERE id = :id'
        );

        $matchedCount = 0;

        foreach ($allServices as $svc) {
            if (!empty($svc['backup_provider_id'])) {
                continue; // Already has a backup
            }

            $currentPid = (int) $svc['provider_id'];
            $subId = (int) $svc['subcategory_id'];
            $catId = (int) $svc['category_id'];

            $candidate = null;

            // 1. Look in same subcategory from an alternative provider
            if (isset($bySubcategory[$subId])) {
                foreach ($bySubcategory[$subId] as $otherPid => $servicesList) {
                    if ($otherPid !== $currentPid && !empty($servicesList)) {
                        $candidate = $servicesList[0];
                        break;
                    }
                }
            }

            // 2. If not found in subcategory, look in same main platform category
            if ($candidate === null && isset($byCategory[$catId])) {
                foreach ($byCategory[$catId] as $otherPid => $servicesList) {
                    if ($otherPid !== $currentPid && !empty($servicesList)) {
                        $candidate = $servicesList[0];
                        break;
                    }
                }
            }

            if ($candidate !== null) {
                $updateStmt->execute([
                    ':bpid' => $candidate['provider_id'],
                    ':bsid' => $candidate['provider_service_id'],
                    ':id' => $svc['id'],
                ]);
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
        $haystack = mb_strtolower($catName . ' ' . $serviceName);

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
        $sub = trim($catName);
        if ($sub === '' || $sub === 'General') {
            $sub = $platform . ' Xizmatlari';
        }

        return [$platform, $sub];
    }

    /**
     * @param array<string, int> $categoriesMap
     */
    private static function getOrCreateCategoryCached(string $name, array &$categoriesMap): int
    {
        $key = mb_strtolower($name);
        if (isset($categoriesMap[$key])) {
            return $categoriesMap[$key];
        }

        Database::execute(
            'INSERT INTO categories (name_uz, name_ru, name_en, is_active) VALUES (:u, :r, :e, 1)',
            ['u' => $name, 'r' => $name, 'e' => $name]
        );

        $id = (int) Database::lastInsertId();
        $categoriesMap[$key] = $id;

        return $id;
    }

    /**
     * @param array<string, int> $subcategoriesMap
     */
    private static function getOrCreateSubcategoryCached(int $categoryId, string $name, array &$subcategoriesMap): int
    {
        $key = $categoryId . '_' . mb_strtolower($name);
        if (isset($subcategoriesMap[$key])) {
            return $subcategoriesMap[$key];
        }

        Database::execute(
            'INSERT INTO subcategories (category_id, name_uz, name_ru, name_en, is_active) VALUES (:cid, :u, :r, :e, 1)',
            ['cid' => $categoryId, 'u' => $name, 'r' => $name, 'e' => $name]
        );

        $id = (int) Database::lastInsertId();
        $subcategoriesMap[$key] = $id;

        return $id;
    }
}
