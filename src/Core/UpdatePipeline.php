<?php

declare(strict_types=1);

namespace App\Core;

use App\Handlers\LanguageHandler;
use App\Handlers\StartHandler;
use App\Services\AdminService;
use App\Services\I18nService;
use App\Services\UserService;

/**
 * The gate-and-dispatch pipeline shared by every entrypoint that feeds a
 * Telegram Update into the bot: the real webhook AND the local dev chat
 * simulator (dev/simulate.php). Kept in one place so the two never drift —
 * that exact kind of duplication (business logic re-implemented slightly
 * differently per entrypoint) was one of the bugs found in the reference
 * codebases this project was learned from.
 */
final class UpdatePipeline
{
    public static function handle(Update $update): void
    {
        $locale = (string) Config::get('default_locale', 'uz');

        if ($update->updateId > 0) {
            $inserted = Database::execute('INSERT IGNORE INTO processed_updates (update_id) VALUES (:id)', ['id' => $update->updateId]);
            if ($inserted === 0) {
                // Already processed (e.g. Telegram redelivered a slow update) — skip silently.
                return;
            }
        }

        if ($update->telegramUserId === null) {
            return;
        }

        $user = UserService::findOrCreateByTelegramId($update->telegramUserId, $update->username, $update->firstName);
        $locale = $user['language'] ?? I18nService::guessFromTelegramCode($update->languageCode);
        $isAdmin = AdminService::isAdmin($update->telegramUserId);

        if ((int) $user['is_banned'] === 1) {
            if ($update->isMessage()) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('start.banned', [], $locale));
            }

            return;
        }

        if (!$isAdmin && (string) AdminService::getSetting('bot_enabled', '1') !== '1') {
            if ($update->isMessage()) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('start.maintenance', [], $locale));
            }

            return;
        }

        $antiflood = (int) AdminService::getSetting('antiflood_seconds', 2);
        if (!$isAdmin && $update->isMessage() && UserService::isFloodLimited((int) $user['id'], $antiflood)) {
            return;
        }

        if ($update->isMessage()) {
            UserService::touchLastMessage((int) $user['id']);
        }

        // Force language selection before anything else, on first contact.
        if ($user['language'] === null) {
            $isLanguageChoice = $update->isCallback() && str_starts_with((string) $update->callbackData, 'lang:set:');
            if (!$isLanguageChoice) {
                LanguageHandler::showPicker($update, $user, $locale);

                return;
            }
        }

        $isSubCheck = $update->isCallback() && ($update->callbackData === 'sub:check');
        if (!$isAdmin && !$isSubCheck && !StartHandler::checkMandatorySubscriptions($update, $locale)) {
            return;
        }

        Router::dispatch($update, $user, $locale);
    }
}
