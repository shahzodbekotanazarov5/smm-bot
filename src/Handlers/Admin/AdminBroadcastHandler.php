<?php

declare(strict_types=1);

namespace App\Handlers\Admin;

use App\Core\SessionState;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\BroadcastService;
use App\Services\I18nService;
use App\Services\UserService;
use App\Support\Keyboard;

final class AdminBroadcastHandler
{
    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        $action = $parts[2] ?? 'menu';

        if ($action === 'menu') {
            self::askMessage($update, $locale);
        }

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }

    private static function askMessage(Update $update, string $locale): void
    {
        SessionState::set((int) $update->telegramUserId, 'admbcast:message', []);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.broadcast.ask_message', [], $locale));
    }

    public static function handleState(array $state, Update $update, array $user, string $locale): void
    {
        $telegramId = (int) $update->telegramUserId;
        $step = $state['step'];

        if ($step === 'admbcast:message') {
            if (!$update->isMessage() || trim((string) $update->text) === '') {
                return;
            }

            $message = trim((string) $update->text);
            $count = UserService::countAll();

            SessionState::set($telegramId, 'admbcast:confirm', ['message' => $message]);

            $buttons = [
                [Keyboard::button(I18nService::t('common.confirm', [], $locale), 'adm:bcast:go')],
                [Keyboard::button(I18nService::t('common.cancel', [], $locale), 'adm:bcast:cancel')],
            ];

            TelegramApi::sendMessage(
                (int) $update->chatId,
                I18nService::t('admin.broadcast.confirm', ['count' => $count], $locale),
                Keyboard::inline($buttons)
            );

            return;
        }

        if ($step === 'admbcast:confirm' && $update->isCallback()) {
            self::handleConfirmCallback($state, $update, $locale);
        }
    }

    private static function handleConfirmCallback(array $state, Update $update, string $locale): void
    {
        $telegramId = (int) $update->telegramUserId;
        $data = (string) $update->callbackData;

        SessionState::clear($telegramId);
        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);

        if ($data === 'adm:bcast:cancel') {
            if ($update->callbackMessageId !== null) {
                TelegramApi::editMessageText((int) $update->chatId, $update->callbackMessageId, I18nService::t('common.cancel', [], $locale));
            }

            return;
        }

        $admin = AdminService::find($telegramId);
        $message = (string) ($state['payload']['message'] ?? '');
        $broadcastId = BroadcastService::create((int) ($admin['id'] ?? 0), $message);

        if ($update->callbackMessageId !== null) {
            TelegramApi::editMessageText((int) $update->chatId, $update->callbackMessageId, I18nService::t('admin.broadcast.started', [], $locale));
        }

        BroadcastService::processBatch($broadcastId, 150);
        $broadcast = BroadcastService::find($broadcastId);

        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.broadcast.done', [
            'sent' => $broadcast['sent_count'] ?? 0,
            'total' => $broadcast['total_recipients'] ?? 0,
        ], $locale));
    }
}
