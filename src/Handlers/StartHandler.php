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
            $rawChatId = trim((string) $channel['chat_id']);
            $checkTarget = $rawChatId;
            if (preg_match('#(?:https?://)?(?:t\.me|telegram\.me)/([A-Za-z0-9_]{4,})#i', $rawChatId, $m)) {
                $checkTarget = '@' . $m[1];
            } elseif (!str_starts_with($rawChatId, '@') && !str_starts_with($rawChatId, '-') && preg_match('/^[A-Za-z0-9_]{4,}$/', $rawChatId)) {
                $checkTarget = '@' . $rawChatId;
            }

            $member = TelegramApi::getChatMember($checkTarget, $telegramId);
            $status = is_array($member) ? ($member['status'] ?? null) : null;
            $joined = in_array($status, ['creator', 'administrator', 'member'], true);

            if (!$joined) {
                $allJoined = false;
            }

            $title = !empty($channel['title']) ? $channel['title'] : $checkTarget;
            $label = ($joined ? '✅ ' : '❌ ') . $title;
            $url = self::formatChannelUrl($rawChatId, $channel['invite_link'] ?? null);
            $buttons[] = [Keyboard::urlButton($label, $url)];
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

    public static function formatChannelUrl(string $chatId, ?string $inviteLink = null): string
    {
        if ($inviteLink !== null && trim($inviteLink) !== '') {
            $clean = trim($inviteLink);
            if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) {
                return $clean;
            }
            if (str_starts_with($clean, 't.me/')) {
                return 'https://' . $clean;
            }
            return 'https://t.me/' . ltrim($clean, '@');
        }

        $chatId = trim($chatId);
        if (str_starts_with($chatId, 'http://') || str_starts_with($chatId, 'https://')) {
            return $chatId;
        }
        if (str_starts_with($chatId, 't.me/')) {
            return 'https://' . $chatId;
        }
        if (str_starts_with($chatId, '@')) {
            return 'https://t.me/' . substr($chatId, 1);
        }
        if (str_starts_with($chatId, '-')) {
            return 'https://t.me';
        }

        return 'https://t.me/' . $chatId;
    }
}
