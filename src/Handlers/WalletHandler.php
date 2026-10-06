<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\I18nService;

final class WalletHandler
{
    public static function showBalance(Update $update, array $user, string $locale): void
    {
        $currency = (string) AdminService::getSetting('currency_label', "so'm");

        $text = I18nService::t('wallet.balance', [
            'balance' => number_format((float) $user['balance'], 2),
            'topup' => number_format((float) $user['total_topup'], 2),
            'spent' => number_format((float) $user['total_spent'], 2),
            'currency' => $currency,
        ], $locale) . "\n\n" . I18nService::t('wallet.topup_instructions', [
            'admin_contact' => (string) AdminService::getSetting('admin_contact', '@admin'),
        ], $locale);

        TelegramApi::sendMessage((int) $update->chatId, $text);
    }

    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }
}
