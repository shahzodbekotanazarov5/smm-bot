<?php

declare(strict_types=1);

namespace App\Handlers\Admin;

use App\Core\Database;
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
            'contact' => self::askContact($update, $locale),
            'channels' => self::showChannels($update, $locale, true),
            'add_ch' => self::askAddChannel($update, $locale),
            'del_ch' => self::deleteChannel((int) ($parts[3] ?? 0), $update, $locale),
            'toggle_bot' => self::toggleBot($update, $locale),
            default => null,
        };

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }

    private static function showMenu(Update $update, string $locale, bool $edit): void
    {
        $markup = (float) AdminService::getSetting('markup_percent', 40);
        $usdRate = (float) AdminService::getSetting('usd_to_local_rate', 12700);
        $adminContact = (string) AdminService::getSetting('admin_contact', '@shahzod_otanazarov');
        $botEnabled = (string) AdminService::getSetting('bot_enabled', '1') === '1';

        $text = I18nService::t('admin.settings.title', [], $locale) . "\n\n"
            . I18nService::t('admin.settings.markup', ['markup' => $markup], $locale) . "\n"
            . I18nService::t('admin.settings.usd_rate', ['rate' => number_format($usdRate, 0, '.', ' ')], $locale) . "\n"
            . I18nService::t('admin.settings.contact', ['contact' => $adminContact], $locale) . "\n"
            . I18nService::t('admin.settings.bot_status', [
                'status' => I18nService::t($botEnabled ? 'admin.settings.status_on' : 'admin.settings.status_off', [], $locale),
            ], $locale);

        $buttons = [
            [Keyboard::button(I18nService::t('admin.settings.markup_button', [], $locale), 'adm:settings:markup')],
            [Keyboard::button(I18nService::t('admin.settings.usd_rate_button', [], $locale), 'adm:settings:usdrate')],
            [Keyboard::button(I18nService::t('admin.settings.contact_button', [], $locale), 'adm:settings:contact')],
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

    private static function askContact(Update $update, string $locale): void
    {
        SessionState::set((int) $update->telegramUserId, 'admsettings:contact', []);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.settings.ask_contact', [], $locale));
    }

    private static function showChannels(Update $update, string $locale, bool $edit): void
    {
        $channels = Database::fetchAll('SELECT * FROM channels ORDER BY id DESC');

        $text = I18nService::t('admin.settings.channels_title', [], $locale) . "\n\n";
        if ($channels === []) {
            $text .= I18nService::t('admin.settings.channels_empty', [], $locale);
        } else {
            foreach ($channels as $ch) {
                $text .= "• <b>" . htmlspecialchars((string) ($ch['title'] ?: $ch['chat_id'])) . "</b> (" . htmlspecialchars((string) $ch['chat_id']) . ")\n";
            }
        }

        $buttons = [];
        foreach ($channels as $ch) {
            $label = "🗑 " . ($ch['title'] ?: $ch['chat_id']);
            $buttons[] = [Keyboard::button($label, "adm:settings:del_ch:{$ch['id']}")];
        }

        $buttons[] = [Keyboard::button(I18nService::t('admin.settings.add_channel_button', [], $locale), 'adm:settings:add_ch')];
        $buttons[] = [Keyboard::button(I18nService::t('common.back', [], $locale), 'adm:settings:menu')];

        if ($edit && $update->callbackMessageId !== null) {
            TelegramApi::editMessageText((int) $update->chatId, $update->callbackMessageId, $text, Keyboard::inline($buttons));
        } else {
            TelegramApi::sendMessage((int) $update->chatId, $text, Keyboard::inline($buttons));
        }
    }

    private static function askAddChannel(Update $update, string $locale): void
    {
        SessionState::set((int) $update->telegramUserId, 'admsettings:add_ch', []);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.settings.ask_channel', [], $locale));
    }

    private static function deleteChannel(int $id, Update $update, string $locale): void
    {
        if ($id > 0) {
            Database::execute('DELETE FROM channels WHERE id = :id', ['id' => $id]);
        }
        self::showChannels($update, $locale, true);
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

            return;
        }

        if ($step === 'admsettings:contact') {
            $clean = trim($text);
            if (!str_starts_with($clean, '@') && !str_starts_with($clean, 'http')) {
                $clean = '@' . $clean;
            }

            AdminService::setSetting('admin_contact', $clean, 'string');
            SessionState::clear($telegramId);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.settings.updated', [], $locale));

            return;
        }

        if ($step === 'admsettings:add_ch') {
            $chatId = $text;
            if (preg_match('#(?:https?://)?(?:t\.me|telegram\.me)/([A-Za-z0-9_]{4,})#i', $text, $m)) {
                $chatId = '@' . $m[1];
            } elseif (!str_starts_with($text, '@') && !str_starts_with($text, '-') && preg_match('/^[A-Za-z0-9_]{4,}$/', $text)) {
                $chatId = '@' . $text;
            }

            $chatInfo = TelegramApi::getChat($chatId);
            $title = (is_array($chatInfo) && !empty($chatInfo['title'])) ? (string) $chatInfo['title'] : $chatId;

            Database::execute(
                'INSERT INTO channels (chat_id, title, is_mandatory, is_active) VALUES (:cid, :title, 1, 1)',
                ['cid' => $chatId, 'title' => $title]
            );

            SessionState::clear($telegramId);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.settings.channel_added', [], $locale));
        }
    }
}
