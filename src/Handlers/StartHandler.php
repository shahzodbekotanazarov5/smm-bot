<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Core\Database;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\I18nService;
use App\Support\Keyboard;

final class StartHandler
{
    public static function handleStart(Update $update, array $user, string $locale): void
    {
        $name = $update->firstName ?? ($user['first_name'] ?? '');

        TelegramApi::sendMessage(
            (int) $update->chatId,
            I18nService::t('start.welcome', ['name' => $name !== '' ? $name : 'foydalanuvchi'], $locale),
            self::mainMenuKeyboard((int) $update->telegramUserId, $locale)
        );
    }

    public static function mainMenuKeyboard(int $telegramId, string $locale): array
    {
        $rows = [
            [I18nService::t('menu.catalog', [], $locale), I18nService::t('menu.orders', [], $locale)],
            [I18nService::t('menu.balance', [], $locale), I18nService::t('menu.support', [], $locale)],
            [I18nService::t('menu.language', [], $locale)],
        ];

        if (AdminService::isAdmin($telegramId)) {
            $rows[] = [I18nService::t('menu.admin', [], $locale)];
        }

        return Keyboard::reply($rows);
    }

    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        $action = $parts[1] ?? '';

        if ($action !== 'check') {
            TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);

            return;
        }

        if (self::checkMandatorySubscriptions($update, $locale, true)) {
            TelegramApi::answerCallbackQuery((string) $update->callbackQueryId, I18nService::t('start.subscribe_ok', [], $locale));
            self::handleStart($update, $user, $locale);
        } else {
            TelegramApi::answerCallbackQuery((string) $update->callbackQueryId, I18nService::t('start.subscribe_missing', [], $locale), true);
        }
    }

    /**
     * Gate checked on every update. Returns true when the user may proceed.
     * When $isRecheck is false and the gate fails, it proactively sends the
     * subscribe prompt — this doubles as both the initial gate and the
     * handler backing the "check again" button.
     */
    public static function checkMandatorySubscriptions(Update $update, string $locale, bool $isRecheck = false): bool
    {
        $channels = Database::fetchAll('SELECT * FROM channels WHERE is_mandatory = 1 AND is_active = 1');

        if ($channels === []) {
            return true;
        }

        $telegramId = (int) $update->telegramUserId;
        $buttons = [];
        $allJoined = true;

        foreach ($channels as $channel) {
            $member = TelegramApi::getChatMember($channel['chat_id'], $telegramId);
            $status = $member['status'] ?? null;
            $joined = in_array($status, ['creator', 'administrator', 'member'], true);

            if (!$joined) {
                $allJoined = false;
            }

            $label = ($joined ? '✅ ' : '❌ ') . ($channel['title'] ?: $channel['chat_id']);
            $username = ltrim((string) $channel['chat_id'], '@');
            $buttons[] = [Keyboard::urlButton($label, "https://t.me/{$username}")];
        }

        if ($allJoined) {
            return true;
        }

        if (!$isRecheck) {
            $buttons[] = [Keyboard::button(I18nService::t('start.subscribe_check', [], $locale), 'sub:check')];
            TelegramApi::sendMessage(
                (int) $update->chatId,
                I18nService::t('start.subscribe_required', [], $locale),
                Keyboard::inline($buttons)
            );
        }

        return false;
    }
}
