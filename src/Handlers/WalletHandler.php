<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\I18nService;
use App\Support\Keyboard;

final class WalletHandler
{
    public static function showBalance(Update $update, array $user, string $locale): void
    {
        $currency = I18nService::t('common.currency', [], $locale);
        $adminContact = (string) AdminService::getSetting('admin_contact', '@shahzod_otanazarov');
        if ($adminContact === '' || $adminContact === '@admin') {
            $adminContact = '@shahzod_otanazarov';
        }

        $url = str_starts_with($adminContact, 'http://') || str_starts_with($adminContact, 'https://')
            ? $adminContact
            : ('https://t.me/' . ltrim($adminContact, '@'));

        $display = str_starts_with($adminContact, '@') ? $adminContact : ('@' . ltrim($adminContact, '@'));

        $text = I18nService::t('wallet.balance', [
            'balance' => number_format((float) $user['balance'], 2),
            'topup' => number_format((float) $user['total_topup'], 2),
            'spent' => number_format((float) $user['total_spent'], 2),
            'currency' => $currency,
        ], $locale) . "\n\n" . I18nService::t('wallet.topup_instructions', [
            'admin_contact' => $display,
        ], $locale);

        $keyboard = Keyboard::inline([
            [Keyboard::urlButton(I18nService::t('wallet.topup_button', [], $locale), $url)],
        ]);

        TelegramApi::sendMessage((int) $update->chatId, $text, $keyboard);
    }

    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
        self::showBalance($update, $user, $locale);
    }
}
