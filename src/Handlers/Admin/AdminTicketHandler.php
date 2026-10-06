<?php

declare(strict_types=1);

namespace App\Handlers\Admin;

use App\Core\SessionState;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\I18nService;
use App\Services\TicketService;
use App\Services\UserService;
use App\Support\Keyboard;

final class AdminTicketHandler
{
    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        $action = $parts[2] ?? 'list';

        match ($action) {
            'list' => self::renderList((int) ($parts[3] ?? 1), $update, $user, $locale, true),
            'view' => self::renderThread((int) ($parts[3] ?? 0), $update, $locale),
            'reply' => self::askReply((int) ($parts[3] ?? 0), $update, $locale),
            'close' => self::closeTicket((int) ($parts[3] ?? 0), $update, $locale),
            default => null,
        };

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }

    public static function showList(Update $update, array $user, string $locale, int $page): void
    {
        self::renderList($page, $update, $user, $locale, false);
    }

    private static function renderList(int $page, Update $update, array $user, string $locale, bool $edit): void
    {
        $page = max(1, $page);
        $result = TicketService::listPage($page);

        if ($result['rows'] === []) {
            self::render($update, I18nService::t('admin.tickets.empty', [], $locale), null, $edit);

            return;
        }

        $lines = [I18nService::t('admin.tickets.title', [], $locale)];
        $buttons = [];

        foreach ($result['rows'] as $ticket) {
            $statusEmoji = match ($ticket['status']) {
                'open' => '🟢',
                'answered' => '🔵',
                default => '⚪️',
            };
            $who = $ticket['username'] ? '@' . $ticket['username'] : $ticket['telegram_id'];
            $buttons[] = [Keyboard::button("{$statusEmoji} #{$ticket['id']} — {$who}", "adm:tkt:view:{$ticket['id']}")];
        }

        $totalPages = max(1, (int) ceil($result['total'] / $result['per_page']));
        $navRow = [];
        if ($page > 1) {
            $navRow[] = Keyboard::button(I18nService::t('common.prev', [], $locale), 'adm:tkt:list:' . ($page - 1));
        }
        if ($page < $totalPages) {
            $navRow[] = Keyboard::button(I18nService::t('common.next', [], $locale), 'adm:tkt:list:' . ($page + 1));
        }
        if ($navRow !== []) {
            $buttons[] = $navRow;
        }
        $buttons[] = [Keyboard::button(I18nService::t('common.back', [], $locale), 'adm:menu:root')];

        self::render($update, implode("\n", $lines), Keyboard::inline($buttons), $edit);
    }

    private static function renderThread(int $ticketId, Update $update, string $locale): void
    {
        $ticket = TicketService::find($ticketId);
        if ($ticket === null) {
            return;
        }

        $target = UserService::find((int) $ticket['user_id']);
        $messages = TicketService::messages($ticketId);

        $who = htmlspecialchars($target['username'] ? '@' . $target['username'] : (string) $target['telegram_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines[] = '👤 ' . $who;
        $lines[] = '';

        foreach ($messages as $message) {
            $prefix = $message['sender_type'] === 'admin' ? '👨‍💻 Admin' : '👤';
            $msgBody = htmlspecialchars((string) $message['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lines[] = "{$prefix} ({$message['created_at']}):\n{$msgBody}";
        }

        $buttons = [
            [Keyboard::button(I18nService::t('admin.tickets.reply_button', [], $locale), "adm:tkt:reply:{$ticketId}")],
        ];
        if ($ticket['status'] !== 'closed') {
            $buttons[] = [Keyboard::button(I18nService::t('admin.tickets.close_button', [], $locale), "adm:tkt:close:{$ticketId}")];
        }
        $buttons[] = [Keyboard::button(I18nService::t('common.back', [], $locale), 'adm:tkt:list:1')];

        TelegramApi::sendMessage((int) $update->chatId, implode("\n", $lines), Keyboard::inline($buttons));
    }

    private static function askReply(int $ticketId, Update $update, string $locale): void
    {
        SessionState::set((int) $update->telegramUserId, 'admtkt:reply', ['ticket_id' => $ticketId]);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.tickets.ask_reply', [], $locale));
    }

    private static function closeTicket(int $ticketId, Update $update, string $locale): void
    {
        TicketService::close($ticketId);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.tickets.closed', [], $locale));
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

        if ($step !== 'admtkt:reply') {
            return;
        }

        $ticketId = (int) $payload['ticket_id'];
        SessionState::clear($telegramId);

        TicketService::addAdminReply($ticketId, $telegramId, $text);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.tickets.replied', [], $locale));

        $ticket = TicketService::find($ticketId);
        if ($ticket === null) {
            return;
        }

        $target = UserService::find((int) $ticket['user_id']);
        if ($target === null) {
            return;
        }

        $userLocale = $target['language'] ?? $locale;
        TelegramApi::sendMessage(
            (int) $target['telegram_id'],
            I18nService::t('support.admin_replied', ['message' => $text], $userLocale)
        );
    }

    private static function render(Update $update, string $text, ?array $keyboard, bool $edit): void
    {
        if ($edit && $update->callbackMessageId !== null) {
            TelegramApi::editMessageText((int) $update->chatId, $update->callbackMessageId, $text, $keyboard);
        } else {
            TelegramApi::sendMessage((int) $update->chatId, $text, $keyboard);
        }
    }
}
