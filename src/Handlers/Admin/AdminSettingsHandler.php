<?php

declare(strict_types=1);

namespace App\Handlers\Admin;

use App\Core\SessionState;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\I18nService;
use App\Support\Keyboard;
use App\Support\Validator;

final class AdminSettingsHandler
{
    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        $action = $parts[2] ?? 'menu';

        match ($action) {
            'menu' => self::showMenu($update, $locale, true),
            'markup' => self::askMarkup($update, $locale),
            'usdrate' => self::askUsdRate($update, $locale),
            'toggle_bot' => self::toggleBot($update, $locale),
            default => null,
        };

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }

    private static function showMenu(Update $update, string $locale, bool $edit): void
    {
        $markup = (float) AdminService::getSetting('markup_percent', 40);
        $usdRate = (float) AdminService::getSetting('usd_to_local_rate', 12700);
        $botEnabled = (string) AdminService::getSetting('bot_enabled', '1') === '1';

        $text = I18nService::t('admin.settings.title', [], $locale) . "\n\n"
            . I18nService::t('admin.settings.markup', ['markup' => $markup], $locale) . "\n"
            . I18nService::t('admin.settings.usd_rate', ['rate' => number_format($usdRate, 0, '.', ' ')], $locale) . "\n"
            . I18nService::t('admin.settings.bot_status', [
                'status' => I18nService::t($botEnabled ? 'admin.settings.status_on' : 'admin.settings.status_off', [], $locale),
            ], $locale);

        $buttons = [
            [Keyboard::button(I18nService::t('admin.settings.markup_button', [], $locale), 'adm:settings:markup')],
            [Keyboard::button(I18nService::t('admin.settings.usd_rate_button', [], $locale), 'adm:settings:usdrate')],
            [Keyboard::button(I18nService::t('admin.settings.toggle_bot_button', [], $locale), 'adm:settings:toggle_bot')],
            [Keyboard::button(I18nService::t('common.back', [], $locale), 'adm:menu:root')],
        ];

        if ($edit && $update->callbackMessageId !== null) {
            TelegramApi::editMessageText((int) $update->chatId, $update->callbackMessageId, $text, Keyboard::inline($buttons));
        } else {
            TelegramApi::sendMessage((int) $update->chatId, $text, Keyboard::inline($buttons));
        }
    }

    private static function askMarkup(Update $update, string $locale): void
    {
        SessionState::set((int) $update->telegramUserId, 'admsettings:markup', []);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.settings.ask_markup', [], $locale));
    }

    private static function askUsdRate(Update $update, string $locale): void
    {
        SessionState::set((int) $update->telegramUserId, 'admsettings:usdrate', []);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.settings.ask_usd_rate', [], $locale));
    }

    private static function toggleBot(Update $update, string $locale): void
    {
        $enabled = (string) AdminService::getSetting('bot_enabled', '1') === '1';
        AdminService::setSetting('bot_enabled', !$enabled, 'bool');
        self::showMenu($update, $locale, true);
    }

    public static function handleState(array $state, Update $update, array $user, string $locale): void
    {
        if (!$update->isMessage() || trim((string) $update->text) === '') {
            return;
        }

        $telegramId = (int) $update->telegramUserId;
        $text = trim((string) $update->text);
        $step = $state['step'];

        if ($step === 'admsettings:markup') {
            $value = Validator::amount($text);
            if ($value === false) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.users.invalid_amount', [], $locale));

                return;
            }

            AdminService::setSetting('markup_percent', $value, 'decimal');
            SessionState::clear($telegramId);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.settings.updated', [], $locale));

            return;
        }

        if ($step === 'admsettings:usdrate') {
            $value = Validator::amount($text);
            if ($value === false) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.users.invalid_amount', [], $locale));

                return;
            }

            AdminService::setSetting('usd_to_local_rate', $value, 'decimal');
            SessionState::clear($telegramId);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.settings.updated', [], $locale));
        }
    }
}
