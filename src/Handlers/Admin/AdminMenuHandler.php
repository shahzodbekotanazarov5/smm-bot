<?php

declare(strict_types=1);

namespace App\Handlers\Admin;

use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\I18nService;
use App\Services\OrderService;
use App\Services\UserService;
use App\Support\Keyboard;

final class AdminMenuHandler
{
    public static function showMenu(Update $update, array $user, string $locale): void
    {
        $currency = (string) AdminService::getSetting('currency_label', "so'm");
        $text = I18nService::t('admin.menu', [], $locale) . "\n\n" . I18nService::t('admin.stats', [
            'users' => UserService::countAll(),
            'orders' => OrderService::countAll(),
            'balance' => number_format(UserService::sumBalances(), 2),
            'currency' => $currency,
        ], $locale);

        $buttons = [
            [Keyboard::button(I18nService::t('admin.menu_catalog', [], $locale), 'adm:cat:menu')],
            [Keyboard::button(I18nService::t('admin.menu_providers', [], $locale), 'adm:prov:menu')],
            [Keyboard::button(I18nService::t('admin.menu_users', [], $locale), 'adm:users:menu')],
            [Keyboard::button(I18nService::t('admin.menu_tickets', [], $locale), 'adm:tkt:list:1')],
            [Keyboard::button(I18nService::t('admin.menu_broadcast', [], $locale), 'adm:bcast:menu')],
            [Keyboard::button(I18nService::t('admin.menu_settings', [], $locale), 'adm:settings:menu')],
        ];

        TelegramApi::sendMessage((int) $update->chatId, $text, Keyboard::inline($buttons));
    }

    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
        self::showMenu($update, $user, $locale);
    }
}
