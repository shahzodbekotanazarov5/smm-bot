<?php

declare(strict_types=1);

namespace App\Support;

final class Keyboard
{
    public static function inline(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    public static function button(string $text, string $callbackData): array
    {
        return ['text' => $text, 'callback_data' => $callbackData];
    }

    public static function urlButton(string $text, string $url): array
    {
        return ['text' => $text, 'url' => $url];
    }

    public static function reply(array $rows, bool $resize = true): array
    {
        return ['keyboard' => $rows, 'resize_keyboard' => $resize];
    }

    public static function removeReply(): array
    {
        return ['remove_keyboard' => true];
    }

    /**
     * Chunks a flat list of buttons into rows of $perRow, then appends any
     * extra rows verbatim (e.g. a pagination row or a back button).
     */
    public static function grid(array $buttons, int $perRow = 1, array $extraRows = []): array
    {
        $rows = $buttons !== [] ? array_chunk($buttons, $perRow) : [];

        foreach ($extraRows as $row) {
            $rows[] = $row;
        }

        return self::inline($rows);
    }
}
