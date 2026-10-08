<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Core\Logger;
use App\Core\SessionState;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\AdminService;
use App\Services\CatalogService;
use App\Services\InsufficientBalanceException;
use App\Services\I18nService;
use App\Services\OrderService;
use App\Support\Keyboard;
use App\Support\Validator;

final class OrderHandler
{
    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        $action = $parts[1] ?? '';

        if ($action === 'start') {
            self::startOrder((int) ($parts[2] ?? 0), $update, $locale);

            return;
        }

        if ($action === 'confirm') {
            self::confirmOrder($update, $user, $locale, (string) ($parts[2] ?? ''));

            return;
        }

        if ($action === 'cancel') {
            SessionState::clear((int) $update->telegramUserId);
            TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
            if ($update->callbackMessageId !== null) {
                TelegramApi::editMessageText((int) $update->chatId, $update->callbackMessageId, I18nService::t('order.canceled_by_user', [], $locale));
            }

            return;
        }

        if ($action === 'hist') {
            TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
            self::renderHistory((int) ($parts[2] ?? 1), $update, $user, $locale, true);

            return;
        }

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }

    private static function startOrder(int $serviceId, Update $update, string $locale): void
    {
        $service = CatalogService::findService($serviceId);

        if ($service === null || (int) $service['is_active'] !== 1) {
            TelegramApi::answerCallbackQuery((string) $update->callbackQueryId, I18nService::t('catalog.empty', [], $locale), true);

            return;
        }

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
        SessionState::set((int) $update->telegramUserId, 'order:awaiting_quantity', ['service_id' => $serviceId]);

        TelegramApi::sendMessage(
            (int) $update->chatId,
            I18nService::t('order.ask_quantity', ['min' => $service['min_quantity'], 'max' => $service['max_quantity']], $locale),
            Keyboard::inline([[Keyboard::button(I18nService::t('common.cancel', [], $locale), 'ord:cancel')]])
        );
    }

    public static function handleState(array $state, Update $update, array $user, string $locale): void
    {
        // Every step in this flow shows an inline "🚫 Bekor qilish" button
        // (and the final step also shows "✅ Tasdiqlash"). While an
        // order:* state is active, Router sends EVERY update here —
        // including those button taps — before it would ever reach the
        // normal callback router, so any 'ord:*' callback must be handled
        // here too or the buttons silently do nothing.
        if ($update->isCallback() && str_starts_with((string) $update->callbackData, 'ord:')) {
            $parts = explode(':', (string) $update->callbackData);
            self::handleCallback($parts, $update, $user, $locale);

            return;
        }

        if (!$update->isMessage() || $update->text === null) {
            return;
        }

        $step = $state['step'];
        $payload = $state['payload'];
        $text = trim((string) $update->text);
        $telegramId = (int) $update->telegramUserId;

        if ($step === 'order:awaiting_quantity') {
            $service = CatalogService::findService((int) $payload['service_id']);
            if ($service === null) {
                SessionState::clear($telegramId);

                return;
            }

            $quantity = OrderService::validateQuantity($service, $text);
            if ($quantity === false) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('order.invalid_quantity', [
                    'min' => $service['min_quantity'],
                    'max' => $service['max_quantity'],
                ], $locale));

                return;
            }

            $payload['quantity'] = $quantity;
            SessionState::set($telegramId, 'order:awaiting_link', $payload);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('order.ask_link', [], $locale));

            return;
        }

        if ($step === 'order:awaiting_link') {
            $service = CatalogService::findService((int) $payload['service_id']);
            if ($service === null) {
                SessionState::clear($telegramId);

                return;
            }

            $link = OrderService::validateLink($service, $text);
            if ($link === false) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('order.invalid_link', [], $locale));

                return;
            }

            $payload['link'] = $link;

            if ($service['order_type'] === 'poll') {
                SessionState::set($telegramId, 'order:awaiting_poll_answer', $payload);
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('order.ask_poll_answer', [], $locale));

                return;
            }

            self::showConfirm($service, $payload, $update, $user, $locale);

            return;
        }

        if ($step === 'order:awaiting_poll_answer') {
            $answer = Validator::pollAnswer($text);
            if ($answer === false) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('order.invalid_poll_answer', [], $locale));

                return;
            }

            $service = CatalogService::findService((int) $payload['service_id']);
            if ($service === null) {
                SessionState::clear($telegramId);

                return;
            }

            $payload['poll_answer'] = $answer;
            self::showConfirm($service, $payload, $update, $user, $locale);
        }
    }

    private static function showConfirm(array $service, array $payload, Update $update, array $user, string $locale): void
    {
        $quantity = (int) $payload['quantity'];
        $price = OrderService::calculatePrice($service, $quantity);
        $currency = I18nService::t('common.currency', [], $locale);
        $nonce = bin2hex(random_bytes(8));
        $payload['nonce'] = $nonce;

        SessionState::set((int) $update->telegramUserId, 'order:awaiting_confirm', $payload);

        $text = I18nService::t('order.confirm', [
            'service' => CatalogService::localizedName($service, $locale),
            'quantity' => $quantity,
            'link' => $payload['link'],
            'price' => number_format($price, 2),
            'currency' => $currency,
            'balance' => number_format((float) $user['balance'], 2),
        ], $locale);

        $buttons = [
            [Keyboard::button(I18nService::t('common.confirm', [], $locale), "ord:confirm:{$nonce}")],
            [Keyboard::button(I18nService::t('common.cancel', [], $locale), 'ord:cancel')],
        ];

        TelegramApi::sendMessage((int) $update->chatId, $text, Keyboard::inline($buttons));
    }

    private static function confirmOrder(Update $update, array $user, string $locale, string $nonce): void
    {
        $telegramId = (int) $update->telegramUserId;
        $state = SessionState::get($telegramId);

        if ($state === null || $state['step'] !== 'order:awaiting_confirm' || ($state['payload']['nonce'] ?? '') !== $nonce) {
            TelegramApi::answerCallbackQuery((string) $update->callbackQueryId, I18nService::t('common.error', [], $locale), true);

            return;
        }

        $payload = $state['payload'];
        SessionState::clear($telegramId);
        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);

        $currency = I18nService::t('common.currency', [], $locale);

        try {
            $order = OrderService::placeOrder(
                (int) $user['id'],
                (int) $payload['service_id'],
                (int) $payload['quantity'],
                (string) $payload['link'],
                isset($payload['poll_answer']) ? (int) $payload['poll_answer'] : null
            );

            self::editOrSend($update, I18nService::t('order.placed', [
                'order_id' => $order['id'],
                'price' => number_format((float) $order['charge_amount'], 2),
                'currency' => $currency,
            ], $locale));
        } catch (InsufficientBalanceException) {
            self::editOrSend($update, I18nService::t('order.insufficient_balance', [], $locale));
        } catch (\Throwable $e) {
            Logger::error('Order placement failed: ' . $e->getMessage());
            self::editOrSend($update, I18nService::t('order.failed', [], $locale));
        }
    }

    private static function editOrSend(Update $update, string $text): void
    {
        if ($update->callbackMessageId !== null) {
            TelegramApi::editMessageText((int) $update->chatId, $update->callbackMessageId, $text);
        } else {
            TelegramApi::sendMessage((int) $update->chatId, $text);
        }
    }

    public static function showHistory(Update $update, array $user, string $locale, int $page): void
    {
        self::renderHistory($page, $update, $user, $locale, false);
    }

    private static function renderHistory(int $page, Update $update, array $user, string $locale, bool $edit): void
    {
        $page = max(1, $page);
        $result = OrderService::historyPage((int) $user['id'], $page);

        if ($result['rows'] === []) {
            self::render($update, I18nService::t('order.history_empty', [], $locale), null, $edit);

            return;
        }

        $currency = I18nService::t('common.currency', [], $locale);
        $lines = [I18nService::t('order.history_title', [], $locale)];

        foreach ($result['rows'] as $order) {
            $lines[] = "\n" . I18nService::t('order.history_item', [
                'id' => $order['id'],
                'status' => I18nService::t('order.status.' . $order['status'], [], $locale),
                'name' => $order['service_name_snapshot'],
                'quantity' => $order['quantity'],
                'price' => number_format((float) $order['charge_amount'], 2),
                'currency' => $currency,
                'date' => $order['created_at'],
            ], $locale);
        }

        $totalPages = max(1, (int) ceil($result['total'] / $result['per_page']));
        $navRow = [];
        if ($page > 1) {
            $navRow[] = Keyboard::button(I18nService::t('common.prev', [], $locale), 'ord:hist:' . ($page - 1));
        }
        $navRow[] = Keyboard::button(I18nService::t('common.page', ['current' => $page, 'total' => $totalPages], $locale), 'noop');
        if ($page < $totalPages) {
            $navRow[] = Keyboard::button(I18nService::t('common.next', [], $locale), 'ord:hist:' . ($page + 1));
        }

        self::render($update, implode("\n", $lines), Keyboard::inline([$navRow]), $edit);
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
