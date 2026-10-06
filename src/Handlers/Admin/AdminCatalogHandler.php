<?php

declare(strict_types=1);

namespace App\Handlers\Admin;

use App\Core\Database;
use App\Core\SessionState;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\CatalogService;
use App\Services\I18nService;
use App\Support\Keyboard;
use App\Support\Validator;

final class AdminCatalogHandler
{
    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        $level = $parts[1] ?? '';
        $action = $parts[2] ?? 'menu';
        $id = isset($parts[3]) ? (int) $parts[3] : null;

        match ($level) {
            'cat' => self::handleCategoryCallback($action, $id, $update, $locale),
            'subc' => self::handleSubcategoryCallback($action, $id, $update, $locale),
            'svc' => self::handleServiceCallback($action, $id, $update, $locale),
            default => null,
        };

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }

    // ==================== Categories ====================

    private static function handleCategoryCallback(string $action, ?int $id, Update $update, string $locale): void
    {
        match ($action) {
            'menu' => self::renderCategories($update, $locale, true),
            'add' => self::startCategoryWizard($update, $locale),
            'view' => self::renderCategoryDetail($id, $update, $locale),
            'toggle' => self::toggleCategory($id, $update, $locale),
            'del' => self::deleteCategory($id, $update, $locale),
            default => null,
        };
    }

    private static function startCategoryWizard(Update $update, string $locale): void
    {
        self::startWizard($update, 'admcat:cat_name_uz', []);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_name_uz', [], $locale));
    }

    private static function renderCategories(Update $update, string $locale, bool $edit): void
    {
        $categories = CatalogService::allCategories();

        $buttons = array_map(
            fn (array $c) => [Keyboard::button(($c['is_active'] ? '✅ ' : '🚫 ') . $c['name_uz'], "adm:cat:view:{$c['id']}")],
            $categories
        );
        $buttons[] = [Keyboard::button(I18nService::t('admin.catalog.add_category', [], $locale), 'adm:cat:add')];
        $buttons[] = [Keyboard::button(I18nService::t('common.back', [], $locale), 'adm:menu:root')];

        self::render($update, I18nService::t('admin.catalog.categories_title', [], $locale), Keyboard::inline($buttons), $edit);
    }

    private static function renderCategoryDetail(?int $id, Update $update, string $locale): void
    {
        $category = $id !== null ? CatalogService::findCategory($id) : null;
        if ($category === null) {
            return;
        }

        $text = I18nService::t('admin.catalog.category_detail', [
            'name' => $category['name_uz'],
            'status' => I18nService::t($category['is_active'] ? 'admin.catalog.status_active' : 'admin.catalog.status_inactive', [], $locale),
        ], $locale);

        $buttons = [
            [Keyboard::button(I18nService::t('admin.catalog.view_subcategories', [], $locale), "adm:subc:menu:{$id}")],
            [Keyboard::button(I18nService::t('admin.catalog.toggle_active_button', [], $locale), "adm:cat:toggle:{$id}")],
            [Keyboard::button('🗑', "adm:cat:del:{$id}")],
            [Keyboard::button(I18nService::t('common.back', [], $locale), 'adm:cat:menu')],
        ];

        self::render($update, $text, Keyboard::inline($buttons), true);
    }

    private static function toggleCategory(?int $id, Update $update, string $locale): void
    {
        if ($id === null) {
            return;
        }

        CatalogService::toggleCategoryActive($id);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.toggle_active', [], $locale));
        self::renderCategoryDetail($id, $update, $locale);
    }

    private static function deleteCategory(?int $id, Update $update, string $locale): void
    {
        if ($id === null) {
            return;
        }

        CatalogService::deleteCategory($id);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.deleted', [], $locale));
        self::renderCategories($update, $locale, false);
    }

    // ==================== Subcategories ====================

    private static function handleSubcategoryCallback(string $action, ?int $id, Update $update, string $locale): void
    {
        if ($action === 'menu') {
            self::renderSubcategories((int) $id, $update, $locale, true);

            return;
        }

        if ($action === 'add') {
            self::startWizard($update, 'admcat:subc_name_uz', ['category_id' => $id]);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_name_uz', [], $locale));

            return;
        }

        if ($action === 'view') {
            self::renderSubcategoryDetail($id, $update, $locale);

            return;
        }

        if ($action === 'toggle' && $id !== null) {
            CatalogService::toggleSubcategoryActive($id);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.toggle_active', [], $locale));
            self::renderSubcategoryDetail($id, $update, $locale);

            return;
        }

        if ($action === 'del' && $id !== null) {
            $sub = CatalogService::findSubcategory($id);
            CatalogService::deleteSubcategory($id);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.deleted', [], $locale));
            if ($sub !== null) {
                self::renderSubcategories((int) $sub['category_id'], $update, $locale, false);
            }
        }
    }

    private static function renderSubcategories(int $categoryId, Update $update, string $locale, bool $edit): void
    {
        $category = CatalogService::findCategory($categoryId);
        $subs = CatalogService::allSubcategories($categoryId);

        $buttons = array_map(
            fn (array $s) => [Keyboard::button(($s['is_active'] ? '✅ ' : '🚫 ') . $s['name_uz'], "adm:subc:view:{$s['id']}")],
            $subs
        );
        $buttons[] = [Keyboard::button(I18nService::t('admin.catalog.add_subcategory', [], $locale), "adm:subc:add:{$categoryId}")];
        $buttons[] = [Keyboard::button(I18nService::t('common.back', [], $locale), "adm:cat:view:{$categoryId}")];

        $title = I18nService::t('admin.catalog.subcategories_title', ['category' => $category['name_uz'] ?? ''], $locale);
        self::render($update, $title, Keyboard::inline($buttons), $edit);
    }

    private static function renderSubcategoryDetail(?int $id, Update $update, string $locale): void
    {
        $sub = $id !== null ? CatalogService::findSubcategory($id) : null;
        if ($sub === null) {
            return;
        }

        $text = I18nService::t('admin.catalog.subcategory_detail', [
            'name' => $sub['name_uz'],
            'status' => I18nService::t($sub['is_active'] ? 'admin.catalog.status_active' : 'admin.catalog.status_inactive', [], $locale),
        ], $locale);

        $buttons = [
            [Keyboard::button(I18nService::t('admin.catalog.view_services', [], $locale), "adm:svc:menu:{$id}")],
            [Keyboard::button(I18nService::t('admin.catalog.toggle_active_button', [], $locale), "adm:subc:toggle:{$id}")],
            [Keyboard::button('🗑', "adm:subc:del:{$id}")],
            [Keyboard::button(I18nService::t('common.back', [], $locale), "adm:subc:menu:{$sub['category_id']}")],
        ];

        self::render($update, $text, Keyboard::inline($buttons), true);
    }

    // ==================== Services ====================

    private static function handleServiceCallback(string $action, ?int $id, Update $update, string $locale): void
    {
        if ($action === 'menu') {
            self::renderServices((int) $id, $update, $locale, true);

            return;
        }

        if ($action === 'add') {
            self::startServiceWizard((int) $id, $update, $locale);

            return;
        }

        if ($action === 'view') {
            self::renderServiceDetail($id, $update, $locale);

            return;
        }

        if ($action === 'toggle' && $id !== null) {
            CatalogService::toggleServiceActive($id);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.toggle_active', [], $locale));
            self::renderServiceDetail($id, $update, $locale);

            return;
        }

        if ($action === 'toggletype' && $id !== null) {
            $service = CatalogService::findService($id);
            if ($service !== null) {
                $new = $service['link_type'] === 'url' ? 'username' : 'url';
                Database::execute('UPDATE services SET link_type = :t WHERE id = :id', ['t' => $new, 'id' => $id]);
            }
            self::renderServiceDetail($id, $update, $locale);

            return;
        }

        if ($action === 'toggleordertype' && $id !== null) {
            $service = CatalogService::findService($id);
            if ($service !== null) {
                $new = $service['order_type'] === 'default' ? 'poll' : 'default';
                Database::execute('UPDATE services SET order_type = :t WHERE id = :id', ['t' => $new, 'id' => $id]);
            }
            self::renderServiceDetail($id, $update, $locale);

            return;
        }

        if ($action === 'del' && $id !== null) {
            $service = CatalogService::findService($id);
            CatalogService::deleteService($id);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.deleted', [], $locale));
            if ($service !== null) {
                self::renderServices((int) $service['subcategory_id'], $update, $locale, false);
            }
        }
    }

    private static function renderServices(int $subcategoryId, Update $update, string $locale, bool $edit): void
    {
        $sub = CatalogService::findSubcategory($subcategoryId);
        $services = CatalogService::allServices($subcategoryId);

        $buttons = array_map(
            fn (array $s) => [Keyboard::button(($s['is_active'] ? '✅ ' : '🚫 ') . $s['name_uz'], "adm:svc:view:{$s['id']}")],
            $services
        );
        $buttons[] = [Keyboard::button(I18nService::t('admin.catalog.add_service', [], $locale), "adm:svc:add:{$subcategoryId}")];
        $buttons[] = [Keyboard::button(I18nService::t('common.back', [], $locale), "adm:subc:view:{$subcategoryId}")];

        $title = I18nService::t('admin.catalog.services_title', ['subcategory' => $sub['name_uz'] ?? ''], $locale);
        self::render($update, $title, Keyboard::inline($buttons), $edit);
    }

    private static function renderServiceDetail(?int $id, Update $update, string $locale): void
    {
        $service = $id !== null ? CatalogService::findService($id) : null;
        if ($service === null) {
            return;
        }

        $currency = (string) AdminService::getSetting('currency_label', "so'm");
        $text = I18nService::t('admin.catalog.service_detail', [
            'name' => $service['name_uz'],
            'price' => number_format((float) $service['price_per_1000'], 0, '.', ' '),
            'currency' => $currency,
            'min' => $service['min_quantity'],
            'max' => $service['max_quantity'],
            'status' => I18nService::t($service['is_active'] ? 'admin.catalog.status_active' : 'admin.catalog.status_inactive', [], $locale),
        ], $locale) . "\n\n🔗 link_type: {$service['link_type']} | order_type: {$service['order_type']}";

        $buttons = [
            [Keyboard::button(I18nService::t('admin.catalog.toggle_active_button', [], $locale), "adm:svc:toggle:{$id}")],
            [Keyboard::button('🔗/👤', "adm:svc:toggletype:{$id}"), Keyboard::button('➡️/🗳', "adm:svc:toggleordertype:{$id}")],
            [Keyboard::button('🗑', "adm:svc:del:{$id}")],
            [Keyboard::button(I18nService::t('common.back', [], $locale), "adm:svc:menu:{$service['subcategory_id']}")],
        ];

        self::render($update, $text, Keyboard::inline($buttons), true);
    }

    private static function startServiceWizard(int $subcategoryId, Update $update, string $locale): void
    {
        $providers = Database::fetchAll('SELECT * FROM providers WHERE is_active = 1 ORDER BY id ASC');

        if ($providers === []) {
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.no_providers', [], $locale));

            return;
        }

        $lines = [I18nService::t('admin.catalog.ask_service_provider', [], $locale)];
        foreach ($providers as $p) {
            $lines[] = "🆔 {$p['id']} — {$p['name']}";
        }

        SessionState::set((int) $update->telegramUserId, 'admcat:svc_provider_id', ['subcategory_id' => $subcategoryId]);
        TelegramApi::sendMessage((int) $update->chatId, implode("\n", $lines));
    }

    private static function startWizard(Update $update, string $step, array $payload): void
    {
        SessionState::set((int) $update->telegramUserId, $step, $payload);
    }

    // ==================== FSM state handling ====================

    public static function handleState(array $state, Update $update, array $user, string $locale): void
    {
        if (!$update->isMessage() || trim((string) $update->text) === '') {
            return;
        }

        $telegramId = (int) $update->telegramUserId;
        $text = trim((string) $update->text);
        $step = $state['step'];
        $payload = $state['payload'];

        match ($step) {
            'admcat:cat_name_uz' => self::stepCategoryNameUz($telegramId, $text, $payload, $update, $locale),
            'admcat:cat_name_ru' => self::stepCategoryNameRu($telegramId, $text, $payload, $update, $locale),
            'admcat:cat_name_en' => self::stepCategoryNameEn($telegramId, $text, $payload, $update, $locale),
            'admcat:subc_name_uz' => self::stepSubcategoryNameUz($telegramId, $text, $payload, $update, $locale),
            'admcat:subc_name_ru' => self::stepSubcategoryNameRu($telegramId, $text, $payload, $update, $locale),
            'admcat:subc_name_en' => self::stepSubcategoryNameEn($telegramId, $text, $payload, $update, $locale),
            'admcat:svc_provider_id' => self::stepServiceProviderId($telegramId, $text, $payload, $update, $locale),
            'admcat:svc_provider_service_id' => self::stepServiceProviderServiceId($telegramId, $text, $payload, $update, $locale),
            'admcat:svc_rate' => self::stepServiceRate($telegramId, $text, $payload, $update, $locale),
            'admcat:svc_name_uz' => self::stepServiceNameUz($telegramId, $text, $payload, $update, $locale),
            'admcat:svc_name_ru' => self::stepServiceNameRu($telegramId, $text, $payload, $update, $locale),
            'admcat:svc_name_en' => self::stepServiceNameEn($telegramId, $text, $payload, $update, $locale),
            'admcat:svc_min' => self::stepServiceMin($telegramId, $text, $payload, $update, $locale),
            'admcat:svc_max' => self::stepServiceMax($telegramId, $text, $payload, $update, $locale),
            default => SessionState::clear($telegramId),
        };
    }

    private static function stepCategoryNameUz(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $payload['name_uz'] = $text;
        SessionState::set($tid, 'admcat:cat_name_ru', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_name_ru', [], $locale));
    }

    private static function stepCategoryNameRu(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $payload['name_ru'] = $text;
        SessionState::set($tid, 'admcat:cat_name_en', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_name_en', [], $locale));
    }

    private static function stepCategoryNameEn(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        CatalogService::createCategory($payload['name_uz'], $payload['name_ru'], $text);
        SessionState::clear($tid);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.category_created', [], $locale));
        self::renderCategories($update, $locale, false);
    }

    private static function stepSubcategoryNameUz(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $payload['name_uz'] = $text;
        SessionState::set($tid, 'admcat:subc_name_ru', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_name_ru', [], $locale));
    }

    private static function stepSubcategoryNameRu(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $payload['name_ru'] = $text;
        SessionState::set($tid, 'admcat:subc_name_en', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_name_en', [], $locale));
    }

    private static function stepSubcategoryNameEn(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $categoryId = (int) $payload['category_id'];
        CatalogService::createSubcategory($categoryId, $payload['name_uz'], $payload['name_ru'], $text);
        SessionState::clear($tid);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.subcategory_created', [], $locale));
        self::renderSubcategories($categoryId, $update, $locale, false);
    }

    private static function stepServiceProviderId(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $providerId = ctype_digit($text) ? (int) $text : false;
        $provider = $providerId !== false ? Database::fetchOne('SELECT * FROM providers WHERE id = :id', ['id' => $providerId]) : null;

        if ($provider === null) {
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_service_provider', [], $locale));

            return;
        }

        $payload['provider_id'] = $provider['id'];
        SessionState::set($tid, 'admcat:svc_provider_service_id', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_service_provider_id', [], $locale));
    }

    private static function stepServiceProviderServiceId(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $payload['provider_service_id'] = $text;
        SessionState::set($tid, 'admcat:svc_rate', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_service_rate', [], $locale));
    }

    private static function stepServiceRate(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $rate = Validator::amount($text);
        if ($rate === false) {
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_service_rate', [], $locale));

            return;
        }

        $provider = Database::fetchOne('SELECT currency FROM providers WHERE id = :id', ['id' => (int) $payload['provider_id']]);
        $markup = (float) AdminService::getSetting('markup_percent', 40);
        $usdRate = (float) AdminService::getSetting('usd_to_local_rate', 12700);
        $price = CatalogService::computeLocalPrice($rate, (string) ($provider['currency'] ?? 'USD'), $usdRate, $markup);

        $payload['rate'] = $rate;
        $payload['price'] = $price;
        SessionState::set($tid, 'admcat:svc_name_uz', $payload);

        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_service_markup_confirm', [
            'markup' => $markup,
            'price' => number_format($price, 0, '.', ' '),
            'currency' => (string) AdminService::getSetting('currency_label', "so'm"),
        ], $locale));
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_name_uz', [], $locale));
    }

    private static function stepServiceNameUz(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $payload['name_uz'] = $text;
        SessionState::set($tid, 'admcat:svc_name_ru', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_name_ru', [], $locale));
    }

    private static function stepServiceNameRu(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $payload['name_ru'] = $text;
        SessionState::set($tid, 'admcat:svc_name_en', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_name_en', [], $locale));
    }

    private static function stepServiceNameEn(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $payload['name_en'] = $text;
        SessionState::set($tid, 'admcat:svc_min', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_service_min', [], $locale));
    }

    private static function stepServiceMin(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $min = Validator::quantity($text, 1, 1000000000);
        if ($min === false) {
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_service_min', [], $locale));

            return;
        }

        $payload['min_quantity'] = $min;
        SessionState::set($tid, 'admcat:svc_max', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_service_max', [], $locale));
    }

    private static function stepServiceMax(int $tid, string $text, array $payload, Update $update, string $locale): void
    {
        $max = Validator::quantity($text, (int) $payload['min_quantity'], 1000000000);
        if ($max === false) {
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.ask_service_max', [], $locale));

            return;
        }

        $subcategoryId = (int) $payload['subcategory_id'];

        CatalogService::createService([
            'subcategory_id' => $subcategoryId,
            'provider_id' => (int) $payload['provider_id'],
            'provider_service_id' => (string) $payload['provider_service_id'],
            'name_uz' => $payload['name_uz'],
            'name_ru' => $payload['name_ru'],
            'name_en' => $payload['name_en'],
            'order_type' => 'default',
            'link_type' => 'url',
            'rate_per_1000' => (float) $payload['rate'],
            'price_per_1000' => (float) $payload['price'],
            'min_quantity' => (int) $payload['min_quantity'],
            'max_quantity' => $max,
            'auto_sync_price' => 1,
        ]);

        SessionState::clear($tid);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.catalog.service_created', [], $locale));
        self::renderServices($subcategoryId, $update, $locale, false);
    }

    private static function render(Update $update, string $text, array $keyboard, bool $edit): void
    {
        if ($edit && $update->callbackMessageId !== null) {
            TelegramApi::editMessageText((int) $update->chatId, $update->callbackMessageId, $text, $keyboard);
        } else {
            TelegramApi::sendMessage((int) $update->chatId, $text, $keyboard);
        }
    }
}
