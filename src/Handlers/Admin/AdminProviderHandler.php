<?php

declare(strict_types=1);

namespace App\Handlers\Admin;

use App\Core\Database;
use App\Core\SessionState;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Services\I18nService;
use App\Services\ProviderClient;
use App\Services\ProviderException;
use App\Support\Keyboard;

final class AdminProviderHandler
{
    public static function handleCallback(array $parts, Update $update, array $user, string $locale): void
    {
        $action = $parts[2] ?? 'menu';

        match ($action) {
            'menu' => self::showList($update, $locale, true),
            'add' => self::startAdd($update, $locale),
            'view' => self::showDetail((int) ($parts[3] ?? 0), $update, $locale),
            'balance' => self::showBalance((int) ($parts[3] ?? 0), $update, $locale),
            'sync' => self::syncServices((int) ($parts[3] ?? 0), $update, $locale),
            'automatch' => self::autoMatchBackups($update, $locale),
            'del' => self::delete((int) ($parts[3] ?? 0), $update, $locale),
            'setcur' => self::handleSetCurrencyCallback((string) ($parts[3] ?? 'USD'), $update, $locale),
            default => null,
        };

        TelegramApi::answerCallbackQuery((string) $update->callbackQueryId);
    }

    private static function showList(Update $update, string $locale, bool $edit): void
    {
        $providers = Database::fetchAll('SELECT * FROM providers ORDER BY id ASC');

        $buttons = array_map(
            fn (array $p) => [Keyboard::button(($p['is_active'] ? '✅ ' : '🚫 ') . $p['name'], "adm:prov:view:{$p['id']}")],
            $providers
        );
        $buttons[] = [Keyboard::button(I18nService::t('admin.providers.add', [], $locale), 'adm:prov:add')];
        if (count($providers) >= 2) {
            $buttons[] = [Keyboard::button("🔄 Zaxira (failover) bog'lash", 'adm:prov:automatch')];
        }
        $buttons[] = [Keyboard::button(I18nService::t('common.back', [], $locale), 'adm:menu:root')];

        $text = I18nService::t('admin.providers.title', [], $locale);
        self::render($update, $text, Keyboard::inline($buttons), $edit);
    }

    private static function showDetail(int $id, Update $update, string $locale): void
    {
        $provider = Database::fetchOne('SELECT * FROM providers WHERE id = :id', ['id' => $id]);
        if ($provider === null) {
            return;
        }

        $buttons = [
            [Keyboard::button("📥 Xizmatlarni yuklab olish", "adm:prov:sync:{$id}")],
            [Keyboard::button(I18nService::t('admin.providers.check_balance_button', [], $locale), "adm:prov:balance:{$id}")],
            [Keyboard::button(I18nService::t('admin.providers.delete_button', [], $locale), "adm:prov:del:{$id}")],
            [Keyboard::button(I18nService::t('common.back', [], $locale), 'adm:prov:menu')],
        ];

        $text = "🔌 <b>{$provider['name']}</b>\n{$provider['api_url']}";
        self::render($update, $text, Keyboard::inline($buttons), true);
    }

    private static function syncServices(int $id, Update $update, string $locale): void
    {
        TelegramApi::sendMessage((int) $update->chatId, "⏳ Provayderdan xizmatlar yuklab olinmoqda, iltimos kuting...");
        try {
            $stats = \App\Services\ProviderSyncService::syncServicesFromProvider($id);
            $text = "✅ <b>Xizmatlar muvaffaqiyatli yuklab olindi!</b>\n\n"
                . "📦 Jami: {$stats['total']} ta\n"
                . "➕ Yangi qo'shildi: {$stats['created']} ta\n"
                . "🔄 Yangilandi: {$stats['updated']} ta\n\n"
                . "Zaxira provayderlar ham avtomatik tekshirildi.";
            TelegramApi::sendMessage((int) $update->chatId, $text);
        } catch (\Throwable $e) {
            TelegramApi::sendMessage((int) $update->chatId, "⚠️ Xatolik yuz berdi: " . $e->getMessage());
        }
    }

    private static function autoMatchBackups(Update $update, string $locale): void
    {
        try {
            $count = \App\Services\ProviderSyncService::autoMatchBackups();
            TelegramApi::sendMessage((int) $update->chatId, "✅ <b>{$count}</b> ta xizmat uchun zaxira provayder (failover) muvaffaqiyatli bog'landi!");
        } catch (\Throwable $e) {
            TelegramApi::sendMessage((int) $update->chatId, "⚠️ Xatolik: " . $e->getMessage());
        }
    }

    private static function showBalance(int $id, Update $update, string $locale): void
    {
        $provider = Database::fetchOne('SELECT * FROM providers WHERE id = :id', ['id' => $id]);
        if ($provider === null) {
            return;
        }

        try {
            $client = new ProviderClient($provider['api_url'], $provider['api_key']);
            $result = $client->balance();
            $balance = $result['balance'] ?? '?';
            $currency = $result['currency'] ?? $provider['currency'];

            Database::execute(
                'UPDATE providers SET balance_cached = :b, balance_checked_at = NOW() WHERE id = :id',
                ['b' => is_numeric($balance) ? $balance : null, 'id' => $id]
            );

            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.providers.balance', [
                'balance' => (string) $balance,
                'currency' => (string) $currency,
            ], $locale));
        } catch (ProviderException $e) {
            TelegramApi::sendMessage((int) $update->chatId, '⚠️ ' . $e->getMessage());
        }
    }

    private static function delete(int $id, Update $update, string $locale): void
    {
        $hasServices = Database::fetchOne('SELECT COUNT(*) AS c FROM services WHERE provider_id = :id', ['id' => $id]);
        if ((int) ($hasServices['c'] ?? 0) > 0) {
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.providers.has_services', [], $locale));

            return;
        }

        Database::execute('DELETE FROM providers WHERE id = :id', ['id' => $id]);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.providers.deleted', [], $locale));
        self::showList($update, $locale, false);
    }

    private static function startAdd(Update $update, string $locale): void
    {
        SessionState::set((int) $update->telegramUserId, 'admprov:name', []);
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.providers.ask_name', [], $locale));
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

        if ($step === 'admprov:name') {
            $payload['name'] = $text;
            SessionState::set($telegramId, 'admprov:url', $payload);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.providers.ask_url', [], $locale));

            return;
        }

        if ($step === 'admprov:url') {
            $url = filter_var($text, FILTER_VALIDATE_URL);
            if ($url === false) {
                TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.providers.ask_url', [], $locale));

                return;
            }

            $payload['url'] = $url;
            SessionState::set($telegramId, 'admprov:key', $payload);
            TelegramApi::sendMessage((int) $update->chatId, I18nService::t('admin.providers.ask_key', [], $locale));

            return;
        }

        if ($step === 'admprov:key') {
            $payload['key'] = $text;
            SessionState::set($telegramId, 'admprov:currency', $payload);

            $buttons = [
                [Keyboard::button("UZS (So'm)", 'adm:prov:setcur:UZS'), Keyboard::button('USD ($)', 'adm:prov:setcur:USD')],
                [Keyboard::button('RUB (₽)', 'adm:prov:setcur:RUB')],
            ];

            TelegramApi::sendMessage(
                (int) $update->chatId,
                I18nService::t('admin.providers.ask_currency', [], $locale),
                Keyboard::inline($buttons)
            );

            return;
        }

        if ($step === 'admprov:currency') {
            $currency = strtoupper(trim($text));
            if (!in_array($currency, ['UZS', 'USD', 'RUB', 'EUR'], true)) {
                $currency = 'USD';
            }

            self::createProvider($payload, $currency, (int) $update->chatId, $telegramId, $locale);
        }
    }

    private static function handleSetCurrencyCallback(string $currency, Update $update, string $locale): void
    {
        $telegramId = (int) $update->telegramUserId;
        $state = SessionState::get($telegramId);

        if ($state === null || $state['step'] !== 'admprov:currency') {
            return;
        }

        self::createProvider($state['payload'], $currency, (int) $update->chatId, $telegramId, $locale);
    }

    private static function createProvider(array $payload, string $currency, int $chatId, int $telegramId, string $locale): void
    {
        Database::execute(
            'INSERT INTO providers (name, api_url, api_key, currency) VALUES (:name, :url, :key, :cur)',
            ['name' => $payload['name'], 'url' => $payload['url'], 'key' => $payload['key'], 'cur' => $currency]
        );

        SessionState::clear($telegramId);
        TelegramApi::sendMessage($chatId, I18nService::t('admin.providers.created', [], $locale));

        $providers = Database::fetchAll('SELECT * FROM providers ORDER BY id ASC');
        $buttons = array_map(
            fn (array $p) => [Keyboard::button(($p['is_active'] ? '✅ ' : '🚫 ') . $p['name'], "adm:prov:view:{$p['id']}")],
            $providers
        );
        $buttons[] = [Keyboard::button(I18nService::t('admin.providers.add', [], $locale), 'adm:prov:add')];
        $buttons[] = [Keyboard::button(I18nService::t('common.back', [], $locale), 'adm:menu:root')];
        TelegramApi::sendMessage($chatId, I18nService::t('admin.providers.title', [], $locale), Keyboard::inline($buttons));
    }

    private static function render(Update $update, string $text, array $keyboard, bool $edit): void
    {
        if ($edit && $update->callbackMessageId !== null) {
            TelegramApi::editMessageText((int) $update->chatId, $update->callbackMessageId, $text, $keyboard);
        } else {
            TelegramApi::sendMessage((int) $update->chatId, $text, $keyboard);
        }
    }
}
