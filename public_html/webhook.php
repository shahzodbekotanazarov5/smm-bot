<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Logger;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Core\UpdatePipeline;
use App\Services\I18nService;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals((string) Config::get('bot.webhook_secret'), (string) $secretHeader)) {
    Logger::warning('Webhook secret token mismatch');
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);

if (!is_array($payload)) {
    http_response_code(200);
    exit;
}

$update = null;

try {
    $update = Update::fromArray($payload);
    UpdatePipeline::handle($update);
} catch (\Throwable $e) {
    Logger::error('Unhandled webhook exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

    if ($update !== null && $update->chatId !== null) {
        TelegramApi::sendMessage((int) $update->chatId, I18nService::t('common.error', [], (string) Config::get('default_locale', 'uz')));
    }
}

http_response_code(200);
