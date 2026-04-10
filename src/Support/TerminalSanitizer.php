<?php

namespace Uncrackable404\ConcurrentConsoleProgress\Support;

use Symfony\Component\Console\Formatter\OutputFormatter;

class TerminalSanitizer
{
    public static function text(mixed $value): string
    {
        $value = self::stringify($value);

        if ($value === '') {
            return '';
        }

        $value = self::stripTerminalControlSequences($value);
        $value = preg_replace('/[\r\n\t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s{2,}/u', ' ', trim($value)) ?? trim($value);

        return OutputFormatter::escape($value);
    }

    public static function visibleText(string $value): string
    {
        $value = preg_replace('/(?<!\\\\)<[^>]+>/', '', $value) ?? $value;

        return str_replace(['\\<', '\\>'], ['<', '>'], $value);
    }

    public static function trimVisibleText(string $value, int $width): string
    {
        if ($width <= 0) {
            return '';
        }

        return OutputFormatter::escape(mb_strimwidth(self::visibleText($value), 0, $width, ''));
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_scalar($value) === false) {
            $value = (string) $value;
        }

        return (string) $value;
    }

    private static function stripTerminalControlSequences(string $value): string
    {
        $patterns = [
            "/\x1B\[[0-?]*[ -\/]*[@-~]/",
            "/\x1B\][^\x1B\x07]*(?:\x07|\x1B\\\\)/",
            "/\x1B[P^_].*?\x1B\\\\/s",
            "/\x1B[@-_]/",
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '', $value) ?? $value;
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    }
}
