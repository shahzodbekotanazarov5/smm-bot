<?php

declare(strict_types=1);

/**
 * LOCAL DEV-ONLY fake upstream SMM provider. Implements just enough of the
 * standard `key`+`action` SMM-panel API contract (services/add/status/balance)
 * to let the bot's real ProviderClient/OrderService code be exercised
 * end-to-end locally, without a real provider account.
 *
 * State is kept in fake_provider_orders.json next to this file (gitignored).
 * Extra dev-only action `simulate_progress` lets you manually flip a fake
 * order's status from the browser to drive the status-sync cron test:
 *   fake_provider.php?action=simulate_progress&order=1&status=Completed
 *   fake_provider.php?action=simulate_progress&order=1&status=Partial&remains=50
 *
 * NEVER deploy this file or point config.php at it in production.
 */

header('Content-Type: application/json');

$stateFile = __DIR__ . '/fake_provider_orders.json';

function loadState(string $file): array
{
    if (!is_file($file)) {
        return ['next_id' => 1, 'orders' => []];
    }

    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : ['next_id' => 1, 'orders' => []];
}

function saveState(string $file, array $state): void
{
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$action = $_REQUEST['action'] ?? '';
$state = loadState($stateFile);

if ($action === 'services') {
    echo json_encode([
        ['service' => '1001', 'name' => 'Instagram Followers (fake)', 'rate' => '1.20', 'min' => '50', 'max' => '10000', 'category' => 'Instagram'],
        ['service' => '1002', 'name' => 'Telegram Channel Members (fake)', 'rate' => '0.80', 'min' => '20', 'max' => '5000', 'category' => 'Telegram'],
    ]);
    exit;
}

if ($action === 'add') {
    $id = $state['next_id']++;
    $state['orders'][(string) $id] = [
        'status' => 'In progress',
        'quantity' => (int) ($_REQUEST['quantity'] ?? 0),
        'start_count' => 100,
        'remains' => (int) ($_REQUEST['quantity'] ?? 0),
        'link' => $_REQUEST['link'] ?? '',
        'service' => $_REQUEST['service'] ?? '',
    ];
    saveState($stateFile, $state);
    echo json_encode(['order' => $id]);
    exit;
}

if ($action === 'status') {
    $orderId = (string) ($_REQUEST['order'] ?? '');
    $order = $state['orders'][$orderId] ?? null;

    if ($order === null) {
        echo json_encode(['error' => 'Incorrect order ID']);
        exit;
    }

    echo json_encode([
        'charge' => '0.10',
        'start_count' => $order['start_count'],
        'status' => $order['status'],
        'remains' => $order['remains'],
        'currency' => 'USD',
    ]);
    exit;
}

if ($action === 'balance') {
    echo json_encode(['balance' => '999.00', 'currency' => 'USD']);
    exit;
}

if ($action === 'simulate_progress') {
    $orderId = (string) ($_REQUEST['order'] ?? '');
    if (!isset($state['orders'][$orderId])) {
        echo json_encode(['error' => 'Unknown order id', 'known_orders' => array_keys($state['orders'])]);
        exit;
    }

    $newStatus = (string) ($_REQUEST['status'] ?? 'Completed');
    $state['orders'][$orderId]['status'] = $newStatus;

    if (isset($_REQUEST['remains'])) {
        $state['orders'][$orderId]['remains'] = (int) $_REQUEST['remains'];
    } elseif ($newStatus === 'Completed') {
        $state['orders'][$orderId]['remains'] = 0;
    }

    saveState($stateFile, $state);
    echo json_encode(['ok' => true, 'order' => $state['orders'][$orderId]]);
    exit;
}

echo json_encode(['error' => 'Unknown action', 'orders' => $state['orders']]);
