<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http;

/**
 * Thin wrapper over the near-universal "SMM panel" provider API contract:
 * a single endpoint, POST key+action(+params), JSON response.
 * TLS verification is always on (Http enforces it) — never disable it here.
 */
final class ProviderClient
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 15
    ) {
    }

    public function services(): array
    {
        return $this->call(['action' => 'services']);
    }

    public function add(array $params): array
    {
        // No retry here: unlike the read-only actions below, "add" is not
        // safely idempotent — if the first attempt actually reached the
        // provider and only the response was lost, retrying could submit a
        // second, duplicate paid order.
        return $this->call(array_merge(['action' => 'add'], $params), retry: false);
    }

    public function status(string $orderId): array
    {
        return $this->call(['action' => 'status', 'order' => $orderId]);
    }

    /**
     * @param string[] $orderIds
     */
    public function multiStatus(array $orderIds): array
    {
        return $this->call(['action' => 'status', 'orders' => implode(',', $orderIds)]);
    }

    public function balance(): array
    {
        return $this->call(['action' => 'balance']);
    }

    private function call(array $params, bool $retry = true): array
    {
        $response = Http::post($this->apiUrl, array_merge(['key' => $this->apiKey], $params), $this->timeout, $retry);

        if (!$response['ok'] || !is_array($response['body'])) {
            throw new ProviderException('Provider request failed: ' . ($response['error'] ?? ('HTTP ' . $response['status'])));
        }

        $body = $response['body'];

        if (is_array($body) && isset($body['error'])) {
            throw new ProviderException((string) $body['error']);
        }

        return $body;
    }
}
