<?php

namespace Uncrackable404\ConcurrentConsoleProgress\Support;

use Countable;
use Stringable;

class Value
{
    public static function blank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_numeric($value) || is_bool($value)) {
            return false;
        }

        if ($value instanceof Stringable) {
            return trim((string) $value) === '';
        }

        if ($value instanceof Countable) {
            return count($value) === 0;
        }

        return empty($value);
    }

    public static function filled(mixed $value): bool
    {
        return self::blank($value) === false;
    }
}
