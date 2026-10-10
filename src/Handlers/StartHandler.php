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
        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
        self::handleStart($update, $user, $locale);
    }

    /**
     * Mandatory subscription gate is disabled. Always returns true.
     */
    public static function checkMandatorySubscriptions(Update $update, string $locale, bool $isRecheck = false): bool
    {
        return true;
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
