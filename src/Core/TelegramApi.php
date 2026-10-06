<?php

declare(strict_types=1);

namespace App\Core;

final class TelegramApi
{
    /**
     * Local dev-only chat simulator support (see dev/simulate.php). When
     * enabled, call() never touches the network: outbound messages are
     * captured in $outbox for the simulator page to render, and read-only
     * methods the gating pipeline needs (getChatMember, getChat) return a
     * harmless fake "everything's fine" response. Production code paths
     * never enable this — it stays false unless a dev entrypoint opts in.
     */
    private static bool $simulate = false;

    private static array $outbox = [];

    private static int $nextMessageId = 1000;

    public static function enableSimulation(): void
    {
        self::$simulate = true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function drainOutbox(): array
    {
        $outbox = self::$outbox;
        self::$outbox = [];

        return $outbox;
    }

    public static function call(string $method, array $params = []): mixed
    {
        if (self::$simulate) {
            return self::simulateCall($method, $params);
        }

        $token = Config::get('bot.token');
        $url = "https://api.telegram.org/bot{$token}/{$method}";

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif ($value === null) {
                unset($params[$key]);
            }
        }

        $response = Http::post($url, $params);

        if (!$response['ok'] || !is_array($response['body']) || empty($response['body']['ok'])) {
            Logger::error('Telegram API call failed', [
                'method' => $method,
                'response' => $response['body'] ?? $response['raw'] ?? $response['error'] ?? null,
            ]);

            return [
                'ok' => false,
                'error' => $response['body'] ?? $response['raw'] ?? $response['error'] ?? 'Unknown failure',
                'status' => $response['status'] ?? null,
            ];
        }

        return $response['body']['result'] ?? true;
    }

    public static function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML'): ?array
    {
        return self::call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true,
            'reply_markup' => $replyMarkup,
        ]);
    }

    public static function sendPhoto(int|string $chatId, string $photo, string $caption = '', ?array $replyMarkup = null): ?array
    {
        return self::call('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup,
        ]);
    }

    public static function editMessageText(int|string $chatId, int $messageId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML'): ?array
    {
        return self::call('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true,
            'reply_markup' => $replyMarkup,
        ]);
    }

    public static function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): ?array
    {
        return self::call('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
    }

    public static function deleteMessage(int|string $chatId, int $messageId): ?array
    {
        return self::call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public static function getChatMember(int|string $chatId, int $userId): ?array
    {
        return self::call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public static function getChat(int|string $chatId): ?array
    {
        return self::call('getChat', ['chat_id' => $chatId]);
    }

    public static function setWebhook(string $url, string $secretToken): mixed
    {
        return self::call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
        ]);
    }

    public static function getWebhookInfo(): mixed
    {
        return self::call('getWebhookInfo');
    }

    private static function simulateCall(string $method, array $params): array
    {
        $outboundMethods = ['sendMessage', 'sendPhoto', 'editMessageText', 'answerCallbackQuery', 'deleteMessage'];

        if (in_array($method, $outboundMethods, true)) {
            // editMessageText/deleteMessage target an EXISTING message id from
            // $params; sendMessage/sendPhoto/answerCallbackQuery need a fresh one.
            $targetsExistingMessage = in_array($method, ['editMessageText', 'deleteMessage'], true);
            $messageId = $targetsExistingMessage
                ? (int) ($params['message_id'] ?? self::$nextMessageId++)
                : self::$nextMessageId++;

            self::$outbox[] = [
                'method' => $method,
                'chat_id' => $params['chat_id'] ?? null,
                'message_id' => $messageId,
                'text' => $params['text'] ?? $params['caption'] ?? null,
                // simulateCall() runs before the JSON-encoding loop in call(), so
                // reply_markup here is still the original PHP array (or null) — no decode needed.
                'reply_markup' => $params['reply_markup'] ?? null,
                'show_alert' => $params['show_alert'] ?? false,
            ];

            return ['message_id' => $messageId, 'chat' => ['id' => $params['chat_id'] ?? 0]];
        }

        return match ($method) {
            'getChatMember' => ['status' => 'member'],
            'getChat' => ['title' => 'Simulated Channel'],
            'getMe' => ['username' => 'simulated_bot', 'id' => 1],
            default => [],
        };
    }
}
