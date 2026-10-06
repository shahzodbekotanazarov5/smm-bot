<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\CatalogService;
use App\Services\I18nService;
use App\Support\Keyboard;

final class CatalogHandler
{
    public static function showCategories(Update $update, array $user, string $locale): void
    {
        $categories = CatalogService::activeCategories();

        if ($categories === []) {
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('catalog.empty', [], $locale));

            return;
        }

        $buttons = array_map(
            fn (array $c) => [Keyboard::button(CatalogService::localizedName($c, $locale), "cat:{$c['id']}")],
            $categories
        );

        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('catalog.choose_category', [], $locale), Keyboard::inline($buttons));
    }

    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        $domain = $parts[0];

        if ($domain === 'cat') {
            if (($parts[1] ?? '') === 'root') {
                self::showCategoriesEdit($update, $locale);
            } else {
                self::showSubcategories((int) $parts[1], $update, $locale);
            }
        } elseif ($domain === 'subc') {
            if (($parts[1] ?? '') === 'back') {
                self::showSubcategories((int) $parts[2], $update, $locale, true);
            } else {
                self::showServices((int) $parts[1], $update, $locale);
            }
        } elseif ($domain === 'svc') {
            if (($parts[1] ?? '') === 'back') {
                self::showServices((int) $parts[2], $update, $locale, true);
            } else {
                self::showServiceDetail((int) $parts[1], $update, $locale);
            }
        }

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }

    private static function showCategoriesEdit(Update $update, string $locale): void
    {
        $categories = CatalogService::activeCategories();

        if ($categories === []) {
            self::render($update, I18nService::t('catalog.empty', [], $locale), null, true);

            return;
        }

        $buttons = array_map(
            fn (array $c) => [Keyboard::button(CatalogService::localizedName($c, $locale), "cat:{$c['id']}")],
            $categories
        );

        self::render($update, I18nService::t('catalog.choose_category', [], $locale), Keyboard::inline($buttons), true);
    }

    private static function showSubcategories(int $categoryId, Update $update, string $locale, bool $edit = false): void
    {
        $category = CatalogService::findCategory($categoryId);
        $subs = CatalogService::activeSubcategories($categoryId);

        if ($category === null || $subs === []) {
            TelegramApi::answerCallbackQuery((string) $update->callbackQueryId, I18nService::t('catalog.empty', [], $locale), true);

            return;
        }

        $buttons = array_map(
            fn (array $s) => [Keyboard::button(CatalogService::localizedName($s, $locale), "subc:{$s['id']}")],
            $subs
        );
        $buttons[] = [Keyboard::button(I18nService::t('common.back', [], $locale), 'cat:root')];

        self::render($update, I18nService::t('catalog.choose_subcategory', [], $locale), Keyboard::inline($buttons), $edit);
    }

    private static function showServices(int $subcategoryId, Update $update, string $locale, bool $edit = false): void
    {
        $sub = CatalogService::findSubcategory($subcategoryId);

        if ($sub === null) {
            return;
        }

        $services = CatalogService::activeServices($subcategoryId);

        if ($services === []) {
            TelegramApi::answerCallbackQuery((string) $update->callbackQueryId, I18nService::t('catalog.empty', [], $locale), true);

            return;
        }

        $currency = (string) AdminService::getSetting('currency_label', "so'm");
        $buttons = array_map(
            fn (array $s) => [Keyboard::button(
                CatalogService::localizedName($s, $locale) . ' — ' . number_format((float) $s['price_per_1000'], 0, '.', ' ') . ' ' . $currency,
                "svc:{$s['id']}"
            )],
            $services
        );
        $buttons[] = [Keyboard::button(I18nService::t('common.back', [], $locale), "subc:back:{$sub['category_id']}")];

        self::render($update, I18nService::t('catalog.choose_service', [], $locale), Keyboard::inline($buttons), $edit);
    }

    private static function showServiceDetail(int $serviceId, Update $update, string $locale): void
    {
        $service = CatalogService::findService($serviceId);

        if ($service === null) {
            return;
        }

        $currency = (string) AdminService::getSetting('currency_label', "so'm");
        $text = I18nService::t('catalog.service_details', [
            'name' => CatalogService::localizedName($service, $locale),
            'price' => number_format((float) $service['price_per_1000'], 0, '.', ' '),
            'currency' => $currency,
            'min' => $service['min_quantity'],
            'max' => $service['max_quantity'],
        ], $locale);

        $buttons = [
            [Keyboard::button(I18nService::t('catalog.order_button', [], $locale), "ord:start:{$service['id']}")],
            [Keyboard::button(I18nService::t('common.back', [], $locale), "svc:back:{$service['subcategory_id']}")],
        ];

        self::render($update, $text, Keyboard::inline($buttons), true);
    }

    private static function render(Update $update, string $text, ?array $keyboard, bool $edit): void
    {
        if ($edit && $update->callbackMessageId !== null) {
            TelegramApi::editMessageText((int) $update->chatId, $update->callbackMessageId, $text, $keyboard);
        } else {
            TelegramApi::sendMessage((int) $update->chatId, $text, $keyboard);
        }
    }
}
