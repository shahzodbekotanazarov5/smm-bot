<?php

declare(strict_types=1);

namespace App\Support;

final class Validator
{
    public static function quantity(mixed $input, int $min, int $max): int|false
    {
        $str = trim((string) $input);

        if (!preg_match('/^\d+$/', $str)) {
            return false;
        }

        $value = (int) $str;

        return ($value >= $min && $value <= $max) ? $value : false;
    }

    public static function link(string $input, string $linkType): string|false
    {
        $input = trim($input);

        if ($linkType === 'username') {
            $clean = ltrim($input, '@');

            return preg_match('/^[A-Za-z0-9_]{3,32}$/', $clean) === 1 ? ('@' . $clean) : false;
        }

        $url = filter_var($input, FILTER_VALIDATE_URL);

        return $url !== false ? $url : false;
    }

    public static function pollAnswer(mixed $input): int|false
    {
        $str = trim((string) $input);

        return preg_match('/^\d+$/', $str) === 1 ? (int) $str : false;
    }

    public static function amount(mixed $input): float|false
    {
        $str = trim((string) $input);

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $str)) {
            return false;
        }

        $value = (float) $str;

        return $value > 0 ? $value : false;
    }

    public static function telegramId(mixed $input): int|false
    {
        $str = trim((string) $input);

        return preg_match('/^\d{5,15}$/', $str) === 1 ? (int) $str : false;
    }
}
