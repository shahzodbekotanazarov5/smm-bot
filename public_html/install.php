<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\AdminService;

header('Content-Type: text/plain; charset=utf-8');

if (PHP_SAPI !== 'cli') {
    $cronToken = (string) Config::get('cron_token', '');
    $providedToken = (string) ($_GET['token'] ?? '');
    $confirmed = ($_GET['confirm'] ?? '') === 'yes';
    $action = (string) ($_GET['action'] ?? 'install');

    if ($cronToken !== '' && !hash_equals($cronToken, $providedToken)) {
        http_response_code(403);
        echo "Forbidden: invalid or missing ?token parameter.\n";
        exit;
    }

    if ($action === 'debug_tg') {
        $token = Config::get('bot.token');
        echo "Token prefix: " . substr((string) $token, 0, 10) . "...\n";
        $getMe = \App\Core\Http::get("https://api.telegram.org/bot{$token}/getMe");
        echo "getMe response:\n" . json_encode($getMe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }

    if ($action === 'webhook_info') {
        $info = \App\Core\TelegramApi::getWebhookInfo();
        echo "Webhook Info:\n" . json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }

    if ($action === 'set_webhook') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'smm-bot-v4gx.onrender.com';
        $webhookUrl = "{$scheme}://{$host}/webhook.php";
        $secretToken = (string) Config::get('bot.webhook_secret', '');

        echo "Registering webhook to: {$webhookUrl}\n";
        $res = \App\Core\TelegramApi::setWebhook($webhookUrl, $secretToken);
        echo "Result:\n" . json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

        $info = \App\Core\TelegramApi::getWebhookInfo();
        echo "Webhook Info:\n" . json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }

    if (!$confirmed) {
        echo "To run the installer via web browser, visit ?confirm=yes" . ($cronToken !== '' ? '&token=YOUR_CRON_TOKEN' : '') . "\n";
        echo "To set webhook: ?action=set_webhook" . ($cronToken !== '' ? '&token=YOUR_CRON_TOKEN' : '') . "\n";
        echo "To view webhook: ?action=webhook_info" . ($cronToken !== '' ? '&token=YOUR_CRON_TOKEN' : '') . "\n";
        exit;
    }
}

$pdo = Database::connection();

$migrationsDir = dirname(__DIR__) . '/src/Migrations';
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files);

$migrationsTableExists = true;
try {
    $pdo->query('SELECT 1 FROM migrations LIMIT 1');
} catch (\Throwable) {
    $migrationsTableExists = false;
}

$applied = [];
if ($migrationsTableExists) {
    foreach (Database::fetchAll('SELECT filename FROM migrations') as $row) {
        $applied[$row['filename']] = true;
    }
}

foreach ($files as $file) {
    $name = basename($file);

    if (isset($applied[$name])) {
        echo "SKIP  {$name} (already applied)\n";
        continue;
    }

    $sql = (string) file_get_contents($file);
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    Database::execute('INSERT INTO migrations (filename) VALUES (:f)', ['f' => $name]);
    echo "OK    {$name}\n";
}

$defaultSettings = [
    'markup_percent' => ['40', 'decimal'],
    'usd_to_local_rate' => ['12700', 'decimal'],
    'currency_label' => ["so'm", 'string'],
    'bot_enabled' => [true, 'bool'],
    'antiflood_seconds' => ['2', 'int'],
    'admin_contact' => ['@admin', 'string'],
    'support_group_chat_id' => ['', 'string'],
];

foreach ($defaultSettings as $key => [$value, $type]) {
    if (Database::fetchOne('SELECT `key` FROM settings WHERE `key` = :k', ['k' => $key]) === null) {
        AdminService::setSetting($key, $value, $type);
        echo "Setting seeded: {$key}\n";
    }
}

$bootstrapIds = (array) Config::get('admin_bootstrap_ids', []);
foreach ($bootstrapIds as $telegramId) {
    $telegramId = (int) $telegramId;
    if ($telegramId <= 0) {
        continue;
    }

    if (AdminService::find($telegramId) === null) {
        Database::execute(
            'INSERT INTO admins (telegram_id, role) VALUES (:tid, :role)',
            ['tid' => $telegramId, 'role' => 'super_admin']
        );
        echo "Admin seeded: {$telegramId}\n";
    }
}

$defaultProvider = (array) Config::get('default_provider', []);
if (!empty($defaultProvider['api_url']) && !empty($defaultProvider['api_key'])) {
    $exists = Database::fetchOne('SELECT id FROM providers WHERE api_url = :url', ['url' => $defaultProvider['api_url']]);

    if ($exists === null) {
        Database::execute(
            'INSERT INTO providers (name, api_url, api_key, currency) VALUES (:name, :url, :key, :cur)',
            [
                'name' => $defaultProvider['name'] ?? 'Default Provider',
                'url' => $defaultProvider['api_url'],
                'key' => $defaultProvider['api_key'],
                'cur' => $defaultProvider['currency'] ?? 'USD',
            ]
        );
        echo "Default provider seeded.\n";
    }
}

// Automatically register Telegram webhook
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
$host = $_SERVER['HTTP_HOST'] ?? 'smm-bot-v4gx.onrender.com';
$webhookUrl = "{$scheme}://{$host}/webhook.php";
$secretToken = (string) Config::get('bot.webhook_secret', '');
echo "\nSetting Telegram Webhook to {$webhookUrl}...\n";
$whResult = \App\Core\TelegramApi::setWebhook($webhookUrl, $secretToken);
echo "Webhook status: " . json_encode($whResult, JSON_UNESCAPED_SLASHES) . "\n";

echo "\nInstall complete. Next: test your bot in Telegram!\n";
