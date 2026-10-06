<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static ?array $data = null;

    public static function load(string $path): void
    {
        self::$data = require $path;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            throw new \RuntimeException('Config not loaded yet.');
        }

        $value = self::$data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function all(): array
    {
        return self::$data ?? [];
    }
}
