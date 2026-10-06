<?php

declare(strict_types=1);

/**
 * Copy this file to config.php (in the same directory) and fill in real values.
 * config.php must NEVER be committed to version control or shared publicly.
 */

return [
    // ⚠️ DB below still points at the LOCAL Docker MySQL used for testing.
    // Bot token/admin are now REAL. Swap 'db' for your real hosting
    // credentials (and set default_provider.api_url once you confirm it —
    // see the TODO below) before actually deploying. See README.md "Install".
    'bot' => [
        'token' => getenv('BOT_TOKEN') ?: '8703800913:AAFzz-P7vWUpBlnOvuIRyolJF1b5w5G2B6w',
        'username' => getenv('BOT_USERNAME') ?: 'pustoyo_bot',
        'webhook_secret' => getenv('WEBHOOK_SECRET') ?: 'local-test-secret-not-for-production',
    ],

    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3307),
        'name' => getenv('DB_NAME') ?: 'smm_bot',
        'user' => getenv('DB_USER') ?: 'smm_bot_user',
        'pass' => getenv('DB_PASS') ?: 'smm_bot_pass',
    ],

    'admin_bootstrap_ids' => [
        7418489132, // @shahzod_otanazarov
        777000111,  // local simulator test admin, harmless to keep
    ],

    'default_locale' => 'uz',
    'supported_locales' => ['uz', 'ru', 'en'],

    'timezone' => 'Asia/Tashkent',

    'default_provider' => [
        'name' => 'NeoSMM',
        'api_url' => 'https://neosmm.uz/api/v2',
        'api_key' => 'gArSnu8yUAcrbqJ3JSBVE7BiauDmEKP5',
        // NeoSMM quotes prices directly in so'm (confirmed via a real
        // balance check), not USD — computeLocalPrice() skips the USD
        // conversion for any provider whose currency isn't 'USD'.
        'currency' => 'UZS',
    ],

    'cron_token' => getenv('CRON_TOKEN') ?: 'local-test-cron-token',
];
