<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\BroadcastService;

if (PHP_SAPI !== 'cli') {
    if (!hash_equals((string) Config::get('cron_token'), (string) ($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!Database::tryLock('smm_broadcast', 0)) {
    echo "Another broadcast run is already in progress, skipping.\n";
    exit;
}

register_shutdown_function(static function (): void {
    Database::releaseLock('smm_broadcast');
});

$pending = BroadcastService::pendingOrRunning();

if ($pending === []) {
    echo "No pending broadcasts.\n";
    exit;
}

foreach ($pending as $broadcast) {
    $result = BroadcastService::processBatch((int) $broadcast['id'], 200);
    echo "Broadcast #{$broadcast['id']}: sent {$result['sent']}, done=" . ($result['done'] ? 'yes' : 'no') . "\n";
}
