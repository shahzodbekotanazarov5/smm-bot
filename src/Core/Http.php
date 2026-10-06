<?php

declare(strict_types=1);

namespace App\Core;

final class Http
{
    public static function post(string $url, array $fields = [], int $timeout = 15, bool $retry = true): array
    {
        return self::request('POST', $url, $fields, $timeout, $retry);
    }

    public static function get(string $url, array $query = [], int $timeout = 15, bool $retry = true): array
    {
        return self::request('GET', $url, $query, $timeout, $retry);
    }

    private static function request(string $method, string $url, array $fields, int $timeout, bool $retry = true): array
    {
        $lastError = '';
        $attempts = $retry ? 2 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init();

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ];

            if ($method === 'POST') {
                $options[CURLOPT_URL] = $url;
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
            } else {
                $options[CURLOPT_URL] = $fields !== [] ? $url . '?' . http_build_query($fields) : $url;
            }

            curl_setopt_array($ch, $options);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno === 0) {
                return [
                    'ok' => $status >= 200 && $status < 300,
                    'status' => $status,
                    'body' => json_decode((string) $raw, true),
                    'raw' => $raw,
                ];
            }

            $lastError = $error;
        }

        Logger::error('HTTP request failed after retry', [
            'url' => Logger::redactString($url),
            'error' => $lastError,
        ]);

        return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => null, 'error' => $lastError];
    }
}
