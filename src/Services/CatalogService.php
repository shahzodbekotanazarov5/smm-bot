<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class CatalogService
{
    // --- Categories ---------------------------------------------------

    public static function activeCategories(): array
    {
        return Database::fetchAll('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
    }

    public static function allCategories(): array
    {
        return Database::fetchAll('SELECT * FROM categories ORDER BY sort_order ASC, id ASC');
    }

    public static function findCategory(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM categories WHERE id = :id', ['id' => $id]);
    }

    public static function createCategory(string $nameUz, string $nameRu, string $nameEn): int
    {
        Database::execute(
            'INSERT INTO categories (name_uz, name_ru, name_en) VALUES (:uz, :ru, :en)',
            ['uz' => $nameUz, 'ru' => $nameRu, 'en' => $nameEn]
        );

        return Database::lastInsertId();
    }

    public static function deleteCategory(int $id): void
    {
        Database::execute('DELETE FROM categories WHERE id = :id', ['id' => $id]);
    }

    public static function toggleCategoryActive(int $id): void
    {
        Database::execute('UPDATE categories SET is_active = 1 - is_active WHERE id = :id', ['id' => $id]);
    }

    // --- Subcategories --------------------------------------------------

    public static function activeSubcategories(int $categoryId): array
    {
        return Database::fetchAll(
            'SELECT * FROM subcategories WHERE category_id = :cid AND is_active = 1 ORDER BY sort_order ASC, id ASC',
            ['cid' => $categoryId]
        );
    }

    public static function allSubcategories(int $categoryId): array
    {
        return Database::fetchAll(
            'SELECT * FROM subcategories WHERE category_id = :cid ORDER BY sort_order ASC, id ASC',
            ['cid' => $categoryId]
        );
    }

    public static function findSubcategory(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM subcategories WHERE id = :id', ['id' => $id]);
    }

    public static function createSubcategory(int $categoryId, string $nameUz, string $nameRu, string $nameEn): int
    {
        Database::execute(
            'INSERT INTO subcategories (category_id, name_uz, name_ru, name_en) VALUES (:cid, :uz, :ru, :en)',
            ['cid' => $categoryId, 'uz' => $nameUz, 'ru' => $nameRu, 'en' => $nameEn]
        );

        return Database::lastInsertId();
    }

    public static function deleteSubcategory(int $id): void
    {
        Database::execute('DELETE FROM subcategories WHERE id = :id', ['id' => $id]);
    }

    public static function toggleSubcategoryActive(int $id): void
    {
        Database::execute('UPDATE subcategories SET is_active = 1 - is_active WHERE id = :id', ['id' => $id]);
    }

    // --- Services ---------------------------------------------------------

    public static function activeServices(int $subcategoryId): array
    {
        return Database::fetchAll(
            'SELECT * FROM services WHERE subcategory_id = :sid AND is_active = 1 ORDER BY sort_order ASC, id ASC',
            ['sid' => $subcategoryId]
        );
    }

    public static function allServices(int $subcategoryId): array
    {
        return Database::fetchAll(
            'SELECT * FROM services WHERE subcategory_id = :sid ORDER BY sort_order ASC, id ASC',
            ['sid' => $subcategoryId]
        );
    }

    public static function findService(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM services WHERE id = :id', ['id' => $id]);
    }

    public static function allActiveServicesWithAutoSync(): array
    {
        return Database::fetchAll(
            "SELECT s.*, p.api_url, p.api_key, p.is_active AS provider_active
             FROM services s JOIN providers p ON p.id = s.provider_id
             WHERE s.auto_sync_price = 1 AND s.is_active = 1 AND p.is_active = 1"
        );
    }

    public static function createService(array $data): int
    {
        Database::execute(
            'INSERT INTO services (
                subcategory_id, provider_id, provider_service_id, name_uz, name_ru, name_en,
                order_type, link_type, rate_per_1000, price_per_1000, min_quantity, max_quantity, auto_sync_price
             ) VALUES (
                :subcategory_id, :provider_id, :provider_service_id, :name_uz, :name_ru, :name_en,
                :order_type, :link_type, :rate_per_1000, :price_per_1000, :min_quantity, :max_quantity, :auto_sync_price
             )',
            $data
        );

        return Database::lastInsertId();
    }

    public static function deleteService(int $id): void
    {
        Database::execute('DELETE FROM services WHERE id = :id', ['id' => $id]);
    }

    public static function toggleServiceActive(int $id): void
    {
        Database::execute('UPDATE services SET is_active = 1 - is_active WHERE id = :id', ['id' => $id]);
    }

    public static function updateServicePrice(int $id, float $ratePer1000, float $pricePer1000): void
    {
        Database::execute(
            'UPDATE services SET rate_per_1000 = :rate, price_per_1000 = :price WHERE id = :id',
            ['rate' => $ratePer1000, 'price' => $pricePer1000, 'id' => $id]
        );
    }

    // --- Naming / pricing helpers -----------------------------------------

    public static function nameColumn(string $locale): string
    {
        return match ($locale) {
            'ru' => 'name_ru',
            'en' => 'name_en',
            default => 'name_uz',
        };
    }

    public static function localizedName(array $row, string $locale): string
    {
        $name = $row[self::nameColumn($locale)] ?? '';

        if ($name === '' || $name === null) {
            $name = $row['name_uz'] ?: ($row['name_en'] ?: $row['name_ru']);
        }

        return (string) $name;
    }

    /**
     * Shared by BOTH the admin "add service" wizard and the price-sync cron
     * so the pricing formula never drifts between entry points (see plan §7a/§8).
     * Only converts when the provider quotes in USD — some providers (e.g.
     * local/regional panels) quote directly in the local currency already,
     * in which case converting again would inflate prices ~12700x. Either
     * way the global markup is applied and baked into the stored price.
     */
    public static function computeLocalPrice(float $providerRatePer1000, string $providerCurrency, float $usdToLocalRate, float $markupPercent): float
    {
        $base = strtoupper($providerCurrency) === 'USD'
            ? $providerRatePer1000 * $usdToLocalRate
            : $providerRatePer1000;

        return round($base + ($base * $markupPercent / 100), 2);
    }
}
