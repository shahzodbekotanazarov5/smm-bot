<?php

declare(strict_types=1);

namespace App\Handlers\Admin;

use App\Core\SessionState;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\I18nService;
use App\Services\UserService;
use App\Services\WalletService;
use App\Support\Keyboard;
use App\Support\Validator;

final class AdminUserHandler
{
    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        $action = $parts[2] ?? 'menu';
        $targetId = isset($parts[3]) ? (int) $parts[3] : null;

        match ($action) {
            'menu' => self::askId($update, $locale),
            'view' => self::showDetail($targetId, $update, $locale),
            'credit' => self::askAmount($targetId, $update, $locale, true),
            'debit' => self::askAmount($targetId, $update, $locale, false),
            'ban' => self::setBanned($targetId, $update, $locale, true),
            'unban' => self::setBanned($targetId, $update, $locale, false),
            default => null,
        };

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }

    private static function askId(Update $update, string $locale): void
    {
        SessionState::set((int) $update->telegramUserId, 'admuser:ask_id', []);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.users.ask_id', [], $locale));
    }

    public static function handleState(array $state, Update $update, array $user, string $locale): void
    {
        if (!$update->isMessage() || trim((string) $update->text) === '') {
            return;
        }

        $telegramId = (int) $update->telegramUserId;
        $text = trim((string) $update->text);
        $step = $state['step'];
        $payload = $state['payload'];

        if ($step === 'admuser:ask_id') {
            $targetTelegramId = Validator::telegramId($text);
            if ($targetTelegramId === false) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.users.not_found', [], $locale));

                return;
            }

            $target = UserService::findByTelegramId($targetTelegramId);
            SessionState::clear($telegramId);

            if ($target === null) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.users.not_found', [], $locale));

                return;
            }

            self::sendDetail($target, $update, $locale);

            return;
        }

        if ($step === 'admuser:credit_amount' || $step === 'admuser:debit_amount') {
            $amount = Validator::amount($text);
            if ($amount === false) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.users.invalid_amount', [], $locale));

                return;
            }

            $targetId = (int) $payload['user_id'];
            $admin = AdminService::find($telegramId);
            $delta = $step === 'admuser:credit_amount' ? $amount : -$amount;

            try {
                $result = WalletService::adjustByAdmin($targetId, $delta, (int) ($admin['id'] ?? 0));
                SessionState::clear($telegramId);
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.users.adjusted', [
                    'balance' => number_format((float) $result['balance'], 2),
                    'currency' => (string) AdminService::getSetting('currency_label', "so'm"),
                ], $locale));

                $target = UserService::find($targetId);
                if ($target !== null) {
                    self::notifyUser($target, $delta, $locale);
                }
            } catch (\Throwable) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.users.invalid_amount', [], $locale));
            }
        }
    }

    private static function showDetail(?int $targetId, Update $update, string $locale): void
    {
        if ($targetId === null) {
            return;
        }

        $target = UserService::find($targetId);
        if ($target === null) {
            return;
        }

        self::sendDetail($target, $update, $locale);
    }

    private static function sendDetail(array $target, Update $update, string $locale): void
    {
        $text = I18nService::t('admin.users.info', [
            'telegram_id' => $target['telegram_id'],
            'username' => $target['username'] ?? '—',
            'balance' => number_format((float) $target['balance'], 2),
            'currency' => (string) AdminService::getSetting('currency_label', "so'm"),
            'orders' => $target['order_count'],
            'banned' => $target['is_banned'] ? '✅' : '❌',
        ], $locale);

        $buttons = [
            [Keyboard::button('➕', "adm:users:credit:{$target['id']}"), Keyboard::button('➖', "adm:users:debit:{$target['id']}")],
            $target['is_banned']
                ? [Keyboard::button('✅', "adm:users:unban:{$target['id']}")]
                : [Keyboard::button('🚫', "adm:users:ban:{$target['id']}")],
            [Keyboard::button(I18nService::t('common.back', [], $locale), 'adm:menu:root')],
        ];

        TelegramApi::sendMessage((int) $update->chatId, $text, Keyboard::inline($buttons));
    }

    private static function askAmount(?int $targetId, Update $update, string $locale, bool $credit): void
    {
        if ($targetId === null) {
            return;
        }

        SessionState::set(
            (int) $update->telegramUserId,
            $credit ? 'admuser:credit_amount' : 'admuser:debit_amount',
            ['user_id' => $targetId]
        );

        TelegramApi::sendMessage(
            (int) $update->chatId,
            I18nService::t($credit ? 'admin.users.ask_credit_amount' : 'admin.users.ask_debit_amount', [], $locale)
        );
    }

    private static function setBanned(?int $targetId, Update $update, string $locale, bool $banned): void
    {
        if ($targetId === null) {
            return;
        }

        UserService::setBanned($targetId, $banned);
        TelegramApi::sendMessage(
            (int) $update->chatId,
            I18nService::t($banned ? 'admin.users.banned' : 'admin.users.unbanned', [], $locale)
        );
    }

    private static function notifyUser(array $target, float $delta, string $locale): void
    {
        $userLocale = $target['language'] ?? $locale;
        $currency = (string) AdminService::getSetting('currency_label', "so'm");

        $key = $delta >= 0 ? 'wallet.credited' : 'wallet.debited';
        $text = I18nService::t($key, [
            'amount' => number_format(abs($delta), 2),
            'balance' => number_format((float) $target['balance'], 2),
            'currency' => $currency,
        ], $userLocale);

        TelegramApi::sendMessage((int) $target['telegram_id'], $text);
    }
}
