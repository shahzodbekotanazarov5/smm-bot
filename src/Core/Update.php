<?php

declare(strict_types=1);

namespace App\Core;

final class Update
{
    public int $updateId = 0;
    public ?array $message = null;
    public ?array $callbackQuery = null;
    public ?array $myChatMember = null;

    public ?int $telegramUserId = null;
    public ?int $chatId = null;
    public ?string $text = null;
    public ?string $callbackData = null;
    public ?int $callbackMessageId = null;
    public ?string $callbackQueryId = null;
    public ?string $languageCode = null;
    public ?string $username = null;
    public ?string $firstName = null;
    public ?array $document = null;
    public ?array $photo = null;

    public static function fromArray(array $data): self
    {
        $update = new self();
        $update->updateId = (int) ($data['update_id'] ?? 0);

        if (isset($data['message'])) {
            $update->message = $data['message'];
            $from = $data['message']['from'] ?? [];
            $update->telegramUserId = isset($from['id']) ? (int) $from['id'] : null;
            $update->chatId = isset($data['message']['chat']['id']) ? (int) $data['message']['chat']['id'] : null;
            $update->text = $data['message']['text'] ?? $data['message']['caption'] ?? null;
            $update->languageCode = $from['language_code'] ?? null;
            $update->username = $from['username'] ?? null;
            $update->firstName = $from['first_name'] ?? null;
            $update->document = $data['message']['document'] ?? null;
            $update->photo = $data['message']['photo'] ?? null;
        } elseif (isset($data['callback_query'])) {
            $update->callbackQuery = $data['callback_query'];
            $from = $data['callback_query']['from'] ?? [];
            $update->telegramUserId = isset($from['id']) ? (int) $from['id'] : null;
            $update->chatId = isset($data['callback_query']['message']['chat']['id'])
                ? (int) $data['callback_query']['message']['chat']['id']
                : null;
            $update->callbackData = $data['callback_query']['data'] ?? null;
            $update->callbackMessageId = isset($data['callback_query']['message']['message_id'])
                ? (int) $data['callback_query']['message']['message_id']
                : null;
            $update->callbackQueryId = $data['callback_query']['id'] ?? null;
            $update->languageCode = $from['language_code'] ?? null;
            $update->username = $from['username'] ?? null;
            $update->firstName = $from['first_name'] ?? null;
        } elseif (isset($data['my_chat_member'])) {
            $update->myChatMember = $data['my_chat_member'];
            $from = $data['my_chat_member']['from'] ?? [];
            $update->telegramUserId = isset($from['id']) ? (int) $from['id'] : null;
        }

        return $update;
    }

    public function isCallback(): bool
    {
        return $this->callbackQuery !== null;
    }

    public function isMessage(): bool
    {
        return $this->message !== null;
    }
}
