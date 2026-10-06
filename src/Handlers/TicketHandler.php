<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Core\SessionState;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\I18nService;
use App\Services\TicketService;

final class TicketHandler
{
    public static function start(Update $update, array $user, string $locale): void
    {
        // Any existing ticket (including a closed one) is reused so a
        // returning user's history stays in one thread — addUserMessage()
        // reopens a closed ticket automatically. A brand new ticket is only
        // created when this user has never had one at all.
        $existing = TicketService::findLatest((int) $user['id']);
        $payload = $existing !== null ? ['ticket_id' => $existing['id']] : [];

        SessionState::set((int) $update->telegramUserId, 'ticket:awaiting_message', $payload);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('support.ask_message', [], $locale));
    }

    public static function handleState(array $state, Update $update, array $user, string $locale): void
    {
        if (!$update->isMessage() || trim((string) $update->text) === '') {
            return;
        }

        $telegramId = (int) $update->telegramUserId;
        $text = trim((string) $update->text);
        SessionState::clear($telegramId);

        $ticketId = $state['payload']['ticket_id'] ?? null;
        $messageId = $update->message['message_id'] ?? null;

        if ($ticketId !== null) {
            TicketService::addUserMessage((int) $ticketId, $telegramId, $text, $messageId);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('support.message_added', [], $locale));

            return;
        }

        $ticket = TicketService::createTicket((int) $user['id'], $text, $messageId);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('support.ticket_created', [], $locale));
        self::notifySupportGroup((int) $ticket['id'], $user, $text);
    }

    /**
     * Fallback route (see Router): any free-text message that doesn't match a
     * command/menu/active-state falls through here when the user has an open
     * ticket, so they don't have to retype /support for follow-ups.
     */
    public static function handleFreeTextMessage(array $ticket, Update $update, array $user, string $locale): void
    {
        $text = trim((string) $update->text);
        if ($text === '') {
            return;
        }

        $messageId = $update->message['message_id'] ?? null;
        TicketService::addUserMessage((int) $ticket['id'], (int) $update->telegramUserId, $text, $messageId);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('support.message_added', [], $locale));
        self::notifySupportGroup((int) $ticket['id'], $user, $text);
    }

    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }

    private static function notifySupportGroup(int $ticketId, array $user, string $message): void
    {
        $chatId = AdminService::getSetting('support_group_chat_id');
        if ($chatId === null || $chatId === '') {
            return;
        }

        $who = htmlspecialchars($user['username'] ? '@' . $user['username'] : (string) $user['telegram_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cleanMsg = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        TelegramApi::sendMessage($chatId, "📨 Murojaat #{$ticketId}\n👤 {$who}\n\n{$cleanMsg}");
    }
}
