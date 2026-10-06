<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\I18nService;
use App\Services\UserService;
use App\Support\Keyboard;

final class LanguageHandler
{
    public static function showPicker(Update $update, array $user, string $locale): void
    {
        $buttons = [
            [Keyboard::button(I18nService::t('language.set_uz', [], $locale), 'lang:set:uz')],
            [Keyboard::button(I18nService::t('language.set_ru', [], $locale), 'lang:set:ru')],
            [Keyboard::button(I18nService::t('language.set_en', [], $locale), 'lang:set:en')],
        ];

        TelegramApi::sendMessage(
            (int) $update->chatId,
            I18nService::t('start.choose_language', [], $locale),
            Keyboard::inline($buttons)
        );
    }

    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        $newLocale = I18nService::normalizeLocale($parts[2] ?? null);
        UserService::setLanguage((int) $user['id'], $newLocale);
        $user['language'] = $newLocale;

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('language.changed', [], $newLocale));

        StartHandler::handleStart($update, $user, $newLocale);
    }
}
