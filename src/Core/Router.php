<?php

declare(strict_types=1);

namespace App\Core;

use App\Handlers\Admin\AdminBroadcastHandler;
use App\Handlers\Admin\AdminCatalogHandler;
use App\Handlers\Admin\AdminMenuHandler;
use App\Handlers\Admin\AdminProviderHandler;
use App\Handlers\Admin\AdminSettingsHandler;
use App\Handlers\Admin\AdminTicketHandler;
use App\Handlers\Admin\AdminUserHandler;
use App\Handlers\CatalogHandler;
use App\Handlers\LanguageHandler;
use App\Handlers\OrderHandler;
use App\Handlers\StartHandler;
use App\Handlers\TicketHandler;
use App\Handlers\WalletHandler;
use App\Services\AdminService;
use App\Services\I18nService;
use App\Services\TicketService;

final class Router
{
    public static function dispatch(Update $update, array $user, string $locale): void
    {
        $telegramId = (int) $update->telegramUserId;

        $state = SessionState::get($telegramId);
        if ($state !== null) {
            if ($update->isMessage()) {
                $text = trim((string) $update->text);
                if (str_starts_with($text, '/')) {
                    SessionState::clear($telegramId);
                    self::routeCommand($text, $update, $user, $locale);

                    return;
                }
                if ($text === I18nService::t('common.cancel', [], $locale) || $text === '🚫 Bekor qilish' || $text === 'Bekor qilish') {
                    SessionState::clear($telegramId);
                    TelegramApi::sendMessage((int) $update->chatId, I18nService::t('common.deleted', [], $locale) ?: 'Bekor qilindi.', StartHandler::mainMenuKeyboard($telegramId, $locale));

                    return;
                }
            }

            if ($update->isCallback()) {
                $cb = (string) $update->callbackData;
                $isStateCallback = str_starts_with($cb, 'ord:confirm')
                    || str_starts_with($cb, 'ord:cancel')
                    || str_starts_with($cb, 'adm:prov:setcur:')
                    || str_starts_with($cb, 'adm:bcast:go')
                    || str_starts_with($cb, 'adm:bcast:cancel');

                if (!$isStateCallback) {
                    SessionState::clear($telegramId);
                    self::routeCallback($update, $user, $locale);

                    return;
                }
            }

            self::routeState($state, $update, $user, $locale);

            return;
        }

        if ($update->isCallback()) {
            self::routeCallback($update, $user, $locale);

            return;
        }

        if ($update->isMessage()) {
            self::routeMessage($update, $user, $locale);
        }
    }

    private static function routeState(array $state, Update $update, array $user, string $locale): void
    {
        $feature = explode(':', (string) $state['step'], 2)[0];

        match ($feature) {
            'order' => OrderHandler::handleState($state, $update, $user, $locale),
            'ticket' => TicketHandler::handleState($state, $update, $user, $locale),
            'admcat' => AdminCatalogHandler::handleState($state, $update, $user, $locale),
            'admprov' => AdminProviderHandler::handleState($state, $update, $user, $locale),
            'admuser' => AdminUserHandler::handleState($state, $update, $user, $locale),
            'admtkt' => AdminTicketHandler::handleState($state, $update, $user, $locale),
            'admbcast' => AdminBroadcastHandler::handleState($state, $update, $user, $locale),
            'admsettings' => AdminSettingsHandler::handleState($state, $update, $user, $locale),
            default => SessionState::clear((int) $update->telegramUserId),
        };
    }

    private static function routeCallback(Update $update, array $user, string $locale): void
    {
        $parts = explode(':', (string) $update->callbackData);
        $domain = $parts[0] ?? '';

        match ($domain) {
            'lang' => LanguageHandler::handleCallback($parts, $update, $user, $locale),
            'sub' => StartHandler::handleCallback($parts, $update, $user, $locale),
            'cat', 'subc', 'svc' => CatalogHandler::handleCallback($parts, $update, $user, $locale),
            'ord' => OrderHandler::handleCallback($parts, $update, $user, $locale),
            'wallet' => WalletHandler::handleCallback($parts, $update, $user, $locale),
            'tkt' => TicketHandler::handleCallback($parts, $update, $user, $locale),
            'adm' => self::routeAdminCallback($parts, $update, $user, $locale),
            default => TelegramApi::answerCallbackQuery((string) $update->callbackQueryId),
        };
    }

    private static function routeAdminCallback(array $parts, Update $update, array $user, string $locale): void
    {
        if (!AdminService::isAdmin((int) $update->telegramUserId)) {
            TelegramApi::answerCallbackQuery((string) $update->callbackQueryId, I18nService::t('admin.not_admin', [], $locale), true);

            return;
        }

        $area = $parts[1] ?? 'menu';

        match ($area) {
            'menu' => AdminMenuHandler::handleCallback($parts, $update, $user, $locale),
            'cat', 'subc', 'svc' => AdminCatalogHandler::handleCallback($parts, $update, $user, $locale),
            'prov' => AdminProviderHandler::handleCallback($parts, $update, $user, $locale),
            'users' => AdminUserHandler::handleCallback($parts, $update, $user, $locale),
            'tkt' => AdminTicketHandler::handleCallback($parts, $update, $user, $locale),
            'bcast' => AdminBroadcastHandler::handleCallback($parts, $update, $user, $locale),
            'settings' => AdminSettingsHandler::handleCallback($parts, $update, $user, $locale),
            default => TelegramApi::answerCallbackQuery((string) $update->callbackQueryId),
        };
    }

    private static function routeMessage(Update $update, array $user, string $locale): void
    {
        $text = trim((string) $update->text);

        if ($text === '') {
            return;
        }

        if (str_starts_with($text, '/')) {
            self::routeCommand($text, $update, $user, $locale);

            return;
        }

        $telegramId = (int) $update->telegramUserId;
        $isAdmin = AdminService::isAdmin($telegramId);

        $menuMap = [
            'menu.catalog' => fn () => CatalogHandler::showCategories($update, $user, $locale),
            'menu.orders' => fn () => OrderHandler::showHistory($update, $user, $locale, 1),
            'menu.balance' => fn () => WalletHandler::showBalance($update, $user, $locale),
            'menu.support' => fn () => TicketHandler::start($update, $user, $locale),
            'menu.language' => fn () => LanguageHandler::showPicker($update, $user, $locale),
        ];

        foreach ($menuMap as $key => $action) {
            if ($text === I18nService::t($key, [], $locale)) {
                $action();

                return;
            }
        }

        if ($isAdmin && $text === I18nService::t('menu.admin', [], $locale)) {
            AdminMenuHandler::showMenu($update, $user, $locale);

            return;
        }

        $openTicket = TicketService::findOpenOrAnswered((int) $user['id']);
        if ($openTicket !== null) {
            TicketHandler::handleFreeTextMessage($openTicket, $update, $user, $locale);

            return;
        }

        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('common.unknown_input', [], $locale));
    }

    private static function routeCommand(string $text, Update $update, array $user, string $locale): void
    {
        $command = strtolower(explode(' ', explode('@', $text)[0])[0]);
        $isAdmin = AdminService::isAdmin((int) $update->telegramUserId);

        match ($command) {
            '/start' => StartHandler::handleStart($update, $user, $locale),
            '/support' => TicketHandler::start($update, $user, $locale),
            '/til', '/language' => LanguageHandler::showPicker($update, $user, $locale),
            '/admin' => $isAdmin
                ? AdminMenuHandler::showMenu($update, $user, $locale)
                : TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.not_admin', [], $locale)),
            '/tickets' => $isAdmin
                ? AdminTicketHandler::showList($update, $user, $locale, 1)
                : TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.not_admin', [], $locale)),
            default => TelegramApi::sendMessage((int) $update->chatId, I18nService::t('common.unknown_input', [], $locale)),
        };
    }
}
