<?php

namespace Uncrackable404\ConcurrentConsoleProgress\Output;

use Uncrackable404\ConcurrentConsoleProgress\Support\TerminalSanitizer;

class ColumnLayout
{
    private const COLUMN_SPACING = 2;

    /**
     * @param  array<int, array{key?: string, label?: string, width?: int, min_width?: int, align?: string}>  $columns
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, int>  $precomputedWidths
     * @return array<int, int>
     */
    public function resolve(array $columns, array $rows, int $availableWidth, array $precomputedWidths = []): array
    {
        $widths = [];

        foreach ($columns as $index => $column) {
            if (isset($column['width'])) {
                $widths[$index] = (int) $column['width'];

                continue;
            }

            $widths[$index] = $precomputedWidths !== []
                ? (int) ($precomputedWidths[$index] ?? $this->naturalWidth($rows, $index))
                : $this->naturalWidth($rows, $index);
        }

        $totalWidth = self::totalWidth($widths);

        if ($totalWidth > $availableWidth) {
            $widths = $this->shrink($columns, $widths, $totalWidth - $availableWidth);
        }

        ksort($widths);

        return $widths;
    }

    private function shrink(array $columns, array $widths, int $overflow): array
    {
        $shrinkable = array_filter(
            array_keys($columns),
            fn (int $index): bool => isset($columns[$index]['width']) === false,
        );

        while ($overflow > 0 && $shrinkable !== []) {
            $changed = false;

            foreach ($shrinkable as $index) {
                $minimum = $this->minimumWidth($columns[$index]);

                if (($widths[$index] ?? 0) <= $minimum) {
                    continue;
                }

                $widths[$index]--;
                $overflow--;
                $changed = true;

                if ($overflow === 0) {
                    break;
                }
            }

            if ($changed === false) {
                break;
            }
        }

        return $widths;
    }

    public static function totalWidth(array $widths): int
    {
        return array_sum($widths)
            + (max(count($widths) - 1, 0) * self::COLUMN_SPACING);
    }

    private function naturalWidth(array $rows, int $index): int
    {
        $max = 0;

        foreach ($rows as $row) {
            $max = max($max, self::width((string) ($row[$index] ?? '')));
        }

        return max($max, 1);
    }

    private function minimumWidth(array $column): int
    {
        if (isset($column['width'])) {
            return (int) $column['width'];
        }

        if (isset($column['min_width'])) {
            return (int) $column['min_width'];
        }

        return max(1, self::width((string) ($column['label'] ?? '')));
    }

    public static function width(string $value): int
    {
        return mb_strwidth(TerminalSanitizer::visibleText($value));
    }
}
