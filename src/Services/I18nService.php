<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

final class I18nService
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    public static function t(string $key, array $vars = [], ?string $locale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $strings = self::load($locale);

        $text = $strings[$key] ?? self::load((string) Config::get('default_locale', 'uz'))[$key] ?? $key;

        foreach ($vars as $name => $value) {
            $val = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
            $escaped = htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text = str_replace('{' . $name . '}', $escaped, $text);
        }

        return $text;
    }

    public static function normalizeLocale(?string $locale): string
    {
        $supported = (array) Config::get('supported_locales', ['uz', 'ru', 'en']);

        if ($locale !== null && in_array($locale, $supported, true)) {
            return $locale;
        }

        return (string) Config::get('default_locale', 'uz');
    }

    /**
     * Best-effort guess from Telegram's language_code, used only to pre-select
     * a sensible default in the language picker — the user must still confirm.
     */
    public static function guessFromTelegramCode(?string $code): string
    {
        if ($code === null) {
            return (string) Config::get('default_locale', 'uz');
        }

        $short = strtolower(substr($code, 0, 2));
        $supported = (array) Config::get('supported_locales', ['uz', 'ru', 'en']);

        return in_array($short, $supported, true) ? $short : (string) Config::get('default_locale', 'uz');
    }

    private static function load(string $locale): array
    {
        if (!isset(self::$cache[$locale])) {
            $file = dirname(__DIR__, 2) . "/lang/{$locale}.php";
            self::$cache[$locale] = is_file($file) ? require $file : [];
        }

        return self::$cache[$locale];
    }
}
