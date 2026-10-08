<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\AdminService;

header('Content-Type: text/plain; charset=utf-8');
if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

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

    if ($action === 'errors') {
        try {
            Database::execute('CREATE TABLE IF NOT EXISTS bot_errors (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                error_message TEXT NOT NULL,
                stack_trace TEXT NOT NULL,
                update_data TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $errors = Database::fetchAll('SELECT * FROM bot_errors ORDER BY id DESC LIMIT 25');
            echo "Total recorded bot errors: " . count($errors) . "\n\n";
            foreach ($errors as $err) {
                echo "[#{$err['id']}] {$err['created_at']}: {$err['error_message']}\n";
                echo "Stack trace:\n{$err['stack_trace']}\n";
                echo "Payload: {$err['update_data']}\n";
                echo str_repeat('-', 50) . "\n";
            }
        } catch (\Throwable $e) {
            echo "Error loading bot_errors table: " . $e->getMessage() . "\n";
        }
        exit;
    }

    if ($action === 'db_inspect') {
        echo "=== DATABASE INSPECTION ===\n";
        $users = Database::fetchAll('SELECT id, telegram_id, username, first_name, language, balance, is_banned, last_message_at FROM users');
        echo "Users (" . count($users) . "):\n" . json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        $states = Database::fetchAll('SELECT * FROM user_states');
        echo "User States (" . count($states) . "):\n" . json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        $providers = Database::fetchAll('SELECT id, name, api_url, currency, is_active FROM providers');
        echo "Providers (" . count($providers) . "):\n" . json_encode($providers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        $channels = Database::fetchAll('SELECT * FROM channels');
        echo "Channels (" . count($channels) . "):\n" . json_encode($channels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        $cats = Database::fetchAll('SELECT id, name_uz, is_active FROM categories');
        echo "Categories (" . count($cats) . "):\n" . json_encode($cats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        $subcatsCount = Database::fetchOne('SELECT COUNT(*) as cnt FROM subcategories');
        echo "Subcategories count: " . ($subcatsCount['cnt'] ?? 0) . "\n";

        $servicesCount = Database::fetchOne('SELECT COUNT(*) as cnt FROM services');
        echo "Services count: " . ($servicesCount['cnt'] ?? 0) . "\n\n";

        $settings = Database::fetchAll('SELECT * FROM settings');
        echo "Settings (" . count($settings) . "):\n" . json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        exit;
    }

    if ($action === 'clear_states') {
        Database::execute('DELETE FROM user_states');
        echo "All user_states cleared successfully.\n";
        exit;
    }

    if ($action === 'clear_channels') {
        Database::execute('DELETE FROM channels');
        echo "All channels deleted successfully from mandatory subscriptions.\n";
        exit;
    }

    if ($action === 'catalog_summary') {
        $cats = Database::fetchAll('SELECT * FROM categories ORDER BY id ASC');
        foreach ($cats as $c) {
            echo "Category #{$c['id']}: UZ: {$c['name_uz']} | RU: {$c['name_ru']} | EN: {$c['name_en']}\n";
            $subs = Database::fetchAll('SELECT sub.*, COUNT(s.id) as svc_cnt FROM subcategories sub LEFT JOIN services s ON s.subcategory_id = sub.id WHERE sub.category_id = :cid GROUP BY sub.id ORDER BY sub.id ASC', ['cid' => $c['id']]);
            foreach ($subs as $s) {
                echo "   -> Sub #{$s['id']}: {$s['name_uz']} ({$s['svc_cnt']} services)\n";
            }
            echo "\n";
        }
        exit;
    }

    if ($action === 'test_providers') {
        $providers = Database::fetchAll('SELECT * FROM providers WHERE is_active = 1');
        foreach ($providers as $p) {
            echo "Testing Provider #{$p['id']}: {$p['name']} ({$p['api_url']})...\n";
            $start = microtime(true);
            try {
                $client = new \App\Services\ProviderClient((string) $p['api_url'], (string) $p['api_key']);
                $services = $client->services();
                $duration = round(microtime(true) - $start, 2);
                echo "Success! Returned " . count($services) . " services in {$duration}s.\n";
                if (!empty($services)) {
                    echo "Sample service 1: " . json_encode($services[0], JSON_UNESCAPED_UNICODE) . "\n";
                }
            } catch (\Throwable $e) {
                echo "Failed in " . round(microtime(true) - $start, 2) . "s: " . $e->getMessage() . "\n";
            }
            echo "\n";
        }
        exit;
    }

    if ($action === 'sync_all_providers') {
        Database::execute("UPDATE providers SET currency = 'UZS' WHERE id = 40144");
        $providers = Database::fetchAll('SELECT id, name FROM providers WHERE is_active = 1');
        foreach ($providers as $p) {
            echo "Syncing provider #{$p['id']}: {$p['name']}...\n";
            $start = microtime(true);
            try {
                $res = \App\Services\ProviderSyncService::syncServicesFromProvider((int) $p['id']);
                $duration = round(microtime(true) - $start, 2);
                echo "Result in {$duration}s: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
            } catch (\Throwable $e) {
                echo "Failed: " . $e->getMessage() . "\n";
            }
        }
        $startMatch = microtime(true);
        $matched = \App\Services\ProviderSyncService::autoMatchBackups();
        $matchDuration = round(microtime(true) - $startMatch, 2);
        echo "Auto-matched failover backups in {$matchDuration}s: {$matched}\n";

        $totalServices = Database::fetchOne('SELECT COUNT(*) as cnt FROM services');
        $totalSubcats = Database::fetchOne('SELECT COUNT(*) as cnt FROM subcategories');
        $totalWithBackup = Database::fetchOne('SELECT COUNT(*) as cnt FROM services WHERE backup_provider_id IS NOT NULL');
        echo "Total active services: " . ($totalServices['cnt'] ?? 0) . "\n";
        echo "Total subcategories: " . ($totalSubcats['cnt'] ?? 0) . "\n";
        echo "Total services with failover backup: " . ($totalWithBackup['cnt'] ?? 0) . "\n";
        exit;
    }

    if ($action === 'automatch') {
        $startMatch = microtime(true);
        $matched = \App\Services\ProviderSyncService::autoMatchBackups();
        $matchDuration = round(microtime(true) - $startMatch, 2);
        echo "Auto-matched failover backups in {$matchDuration}s: {$matched}\n";
        $totalWithBackup = Database::fetchOne('SELECT COUNT(*) as cnt FROM services WHERE backup_provider_id IS NOT NULL');
        echo "Total services with failover backup: " . ($totalWithBackup['cnt'] ?? 0) . "\n";
        exit;
    }

    if ($action === 'curate_catalog') {
        echo "Curating and simplifying catalog...\n";
        $start = microtime(true);
        $res = \App\Services\CatalogCuratorService::curate();
        $dur = round(microtime(true) - $start, 2);
        echo "Curated in {$dur}s! " . json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

        $activeCats = Database::fetchAll('SELECT id, name_uz, name_ru, name_en FROM categories WHERE is_active = 1 ORDER BY sort_order ASC');
        echo "\nActive Categories (" . count($activeCats) . "):\n";
        foreach ($activeCats as $c) {
            echo "- #{$c['id']}: {$c['name_uz']} | {$c['name_ru']} | {$c['name_en']}\n";
            $subs = Database::fetchAll('SELECT id, name_uz, name_ru, name_en FROM subcategories WHERE category_id = :cid AND is_active = 1 ORDER BY sort_order ASC', ['cid' => $c['id']]);
            foreach ($subs as $s) {
                $svcs = Database::fetchAll('SELECT id, name_uz, price_per_1000, provider_id, backup_provider_id FROM services WHERE subcategory_id = :sid AND is_active = 1 ORDER BY sort_order ASC', ['sid' => $s['id']]);
                echo "   * {$s['name_uz']} | {$s['name_ru']} | {$s['name_en']} (" . count($svcs) . " services):\n";
                foreach ($svcs as $v) {
                    echo "       • {$v['name_uz']} — {$v['price_per_1000']} so'm [P{$v['provider_id']} / Backup: P{$v['backup_provider_id']}]\n";
                }
            }
        }
        exit;
    }

    if ($action === 'sample_services') {
        $samples = Database::fetchAll('SELECT s.id, s.name_uz, s.price_per_1000, s.provider_id, s.provider_service_id, s.backup_provider_id, s.backup_service_id, c.name_uz as category_name, sub.name_uz as subcategory_name FROM services s JOIN subcategories sub ON sub.id = s.subcategory_id JOIN categories c ON c.id = sub.category_id WHERE s.backup_provider_id IS NOT NULL LIMIT 5');
        echo json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }

    if ($action === 'find_best_services') {
        $providers = Database::fetchAll('SELECT * FROM providers WHERE is_active = 1');
        foreach ($providers as $p) {
            echo "========================================\n";
            echo "PROVIDER #{$p['id']}: {$p['name']} ({$p['currency']})\n";
            echo "========================================\n";
            $client = new \App\Services\ProviderClient((string) $p['api_url'], (string) $p['api_key']);
            $services = $client->services();
            echo "Total services: " . count($services) . "\n\n";

            // Group by category
            $byCat = [];
            foreach ($services as $s) {
                $cat = $s['category'] ?? 'Uncategorized';
                $byCat[$cat][] = $s;
            }

            foreach ($byCat as $catName => $items) {
                echo "Category: {$catName} (" . count($items) . " services)\n";
                // Show sample 3 services
                foreach (array_slice($items, 0, 3) as $it) {
                    echo "  -> ID: {$it['service']} | Name: {$it['name']} | Rate: {$it['rate']} | Min: {$it['min']} | Max: {$it['max']}\n";
                }
            }
            echo "\n";
        }
        exit;
    }

    if ($action === 'logs') {
        $appLog = dirname(__DIR__) . '/logs/app.log';
        $phpLog = dirname(__DIR__) . '/logs/php_errors.log';
        echo "=== APP.LOG ===\n";
        if (is_file($appLog)) {
            $lines = file($appLog);
            echo implode('', array_slice($lines, -60));
        } else {
            echo "No app.log found.\n";
        }
        echo "\n=== PHP_ERRORS.LOG ===\n";
        if (is_file($phpLog)) {
            $lines = file($phpLog);
            echo implode('', array_slice($lines, -60));
        } else {
            echo "No php_errors.log found.\n";
        }
        exit;
    }

    if ($action === 'debug_tg') {
        echo "BUILD: v5-curate-catalog\n";
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
        try {
            $pdo->exec($statement);
        } catch (\Throwable $e) {
            // Log or ignore non-fatal migration warnings (e.g. duplicate column)
        }
    }

    try {
        Database::execute('INSERT INTO migrations (filename) VALUES (:f)', ['f' => $name]);
    } catch (\Throwable) {}
    echo "OK    {$name}\n";
}

$defaultSettings = [
    'markup_percent' => ['40', 'decimal'],
    'usd_to_local_rate' => ['12700', 'decimal'],
    'currency_label' => ["so'm", 'string'],
    'bot_enabled' => [true, 'bool'],
    'antiflood_seconds' => ['2', 'int'],
    'admin_contact' => ['@shahzod_otanazarov', 'string'],
    'support_group_chat_id' => ['', 'string'],
];

foreach ($defaultSettings as $key => [$value, $type]) {
    $existing = Database::fetchOne('SELECT `value` FROM settings WHERE `key` = :k', ['k' => $key]);
    if ($existing === null) {
        AdminService::setSetting($key, $value, $type);
        echo "Setting seeded: {$key}\n";
    } elseif ($key === 'admin_contact' && ($existing['value'] === '@admin' || empty($existing['value']))) {
        AdminService::setSetting($key, '@shahzod_otanazarov', 'string');
        echo "Setting updated: admin_contact -> @shahzod_otanazarov\n";
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
