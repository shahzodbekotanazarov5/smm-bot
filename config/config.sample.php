<?php

declare(strict_types=1);

/**
 * Copy this file to config.php (in the same directory) and fill in real values.
 * config.php must NEVER be committed to version control or shared publicly.
 */

return [
    'bot' => [
        // Get this from @BotFather
        'token' => 'PUT_YOUR_BOT_TOKEN_HERE',
        'username' => 'your_bot_username',
        // Random long string, also passed to setWebhook. Generate with: bin2hex(random_bytes(32))
        'webhook_secret' => 'PUT_A_RANDOM_SECRET_HERE',
    ],

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'smm_bot',
        'user' => 'smm_bot_user',
        'pass' => 'PUT_YOUR_DB_PASSWORD_HERE',
    ],

    // Telegram user IDs that become admins on first install (install.php seeds these into the admins table).
    'admin_bootstrap_ids' => [
        // 123456789,
    ],

    'default_locale' => 'uz',
    'supported_locales' => ['uz', 'ru', 'en'],

    'timezone' => 'Asia/Tashkent',

    // Optional: seeded into the providers table on install. You can also add/edit providers later from the admin panel.
    'default_provider' => [
        'name' => 'Default Provider',
        'api_url' => 'https://provider.example.com/api/v2',
        'api_key' => 'PUT_YOUR_PROVIDER_API_KEY_HERE',
        'currency' => 'USD',
    ],

    // Shared secret required as ?token= on cron scripts when your host only offers URL-based (not CLI) cron.
    'cron_token' => 'PUT_A_RANDOM_CRON_SECRET_HERE',
];
