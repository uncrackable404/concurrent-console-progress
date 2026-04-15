<?php

namespace Uncrackable404\ConcurrentConsoleProgress\Output;

use Symfony\Component\Console\Terminal;
use Uncrackable404\ConcurrentConsoleProgress\Support\TerminalSanitizer;
use Uncrackable404\ConcurrentConsoleProgress\Support\Value;

class ProgressTableRenderer
{
    private const PROGRESS_BAR_WIDTH = 20;

    private array $stickyWidths = [];

    public function __construct(
        private array $columns,
        private array $footer,
        private Terminal $terminal = new Terminal,
        private ColumnLayout $layout = new ColumnLayout,
    ) {}

    public function render(array $rows, array $global, array $session): string
    {
        $columns = $this->tableColumns();
        $availableWidth = max($this->terminal->getWidth() - 1, 20);
        $availableHeight = max($this->terminal->getHeight() - 1, 6);
        $tableRows = $this->tableRows($rows, $global, $session, self::PROGRESS_BAR_WIDTH, $availableHeight);
        $widths = $this->layout->resolve(
            columns: $columns,
            rows: $this->layoutRows($columns, $tableRows),
            availableWidth: $availableWidth,
        );
        $widths = $this->stabilizeWidths($columns, $widths, $availableWidth);

        $resolvedColumns = $this->resolvedColumns($columns, $widths);
        $table = new TableRenderer($resolvedColumns);

        return $table->render($tableRows);
    }

    private function tableColumns(): array
    {
        $columns = [
            ['key' => 'state', 'label' => '', 'width' => 1, 'min_width' => 1],
        ];

        foreach ($this->columns as $column) {
            $columns[] = match ($column['key']) {
                'progress' => [...$column, 'width' => self::PROGRESS_BAR_WIDTH + 2],
                'percent' => [...$column, 'min_width' => 4],
                'processed' => [...$column, 'min_width' => 9],
                'tasks' => [...$column, 'min_width' => 3],
                default => $column,
            };
        }

        return $columns;
    }

    private function tableRows(
        array $rows,
        array $global,
        array $session,
        int $progressBarWidth,
        int $availableHeight,
    ): array {
        $queueRows = [];

        foreach (array_values($rows) as $row) {
            $queueRows[] = [
                'cells' => $this->rowCells($row, $progressBarWidth),
                'style' => $this->rowStyle($row),
                'active' => $this->rowIsActive($row),
            ];
        }

        $queueRows = $this->visibleQueueRows(
            rows: $queueRows,
            availableHeight: $availableHeight,
            footerLines: count($this->footer),
        );

        $tableRows = $queueRows;

        $tableRows[] = [
            'cells' => $this->rowCells($this->summaryRow($rows, $session), $progressBarWidth),
            'style' => 'options=bold',
        ];

        foreach ($this->footerRows($global, $session) as $row) {
            $tableRows[] = $row;
        }

        return $tableRows;
    }

    private function visibleQueueRows(array $rows, int $availableHeight, int $footerLines): array
    {
        $reservedLines = 1 + 1 + $footerLines;
        $maxQueueRows = max($availableHeight - $reservedLines, 0);

        if (count($rows) <= $maxQueueRows) {
            return array_map($this->stripInternalRowMetadata(...), $rows);
        }

        $selectedIndexes = [];

        foreach ($rows as $index => $row) {
            if (($row['active'] ?? false) !== true) {
                continue;
            }

            $selectedIndexes[$index] = true;

            if (count($selectedIndexes) >= $maxQueueRows) {
                break;
            }
        }

        foreach ($rows as $index => $row) {
            if (count($selectedIndexes) >= $maxQueueRows) {
                break;
            }

            if (array_key_exists($index, $selectedIndexes)) {
                continue;
            }

            $selectedIndexes[$index] = true;
        }

        $visibleRows = [];

        foreach ($rows as $index => $row) {
            if (array_key_exists($index, $selectedIndexes) === false) {
                continue;
            }

            $visibleRows[] = $this->stripInternalRowMetadata($row);
        }

        return $visibleRows;
    }

    private function stripInternalRowMetadata(array $row): array
    {
        unset($row['active']);

        return $row;
    }

    private function rowCells(array $row, int $progressBarWidth): array
    {
        $cells = [$this->stateCell($row)];

        foreach ($this->columns as $column) {
            $cells[] = $this->cellValue($column['key'], $row, $progressBarWidth);
        }

        return $cells;
    }

    private function footerRows(array $global, array $session): array
    {
        $rows = [];
        $cellsCount = count($this->columns) + 1;
        $labelIndex = $this->columnIndex('label');
        $progressIndex = $this->columnIndex('progress');

        foreach ($this->footer as $item) {
            $value = match ($item['key']) {
                'elapsed' => $this->formatDuration((int) ($session['elapsed'] ?? 0)),
                'eta' => $this->formatEta($session['eta'] ?? null),
                default => $this->formatValue($global[$item['key']] ?? null),
            };

            $cells = array_fill(0, $cellsCount, '');
            $cells[0] = ' ';

            if ($labelIndex !== null) {
                $cells[$labelIndex] = (string) $item['label'];
            }

            if ($progressIndex !== null) {
                $cells[$progressIndex] = (string) $value;
            }

            $rows[] = [
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    private function cellValue(string $key, array $row, int $progressBarWidth): string
    {
        $processed = min((int) ($row['processed'] ?? 0), (int) ($row['total'] ?? 0));
        $total = (int) ($row['total'] ?? 0);
        $ratio = $total === 0 ? 1 : min($processed / $total, 1);

        return match ($key) {
            'label' => TerminalSanitizer::text($row['label'] ?? ''),
            'progress' => $this->progressBar($processed, $total, $progressBarWidth),
            'percent' => round($ratio * 100) . '%',
            'processed' => number_format($processed) . ' / ' . number_format($total),
            'tasks' => ((int) ($row['tasks_completed'] ?? 0)) . ' / ' . ((int) ($row['tasks_total'] ?? 0)),
            default => $this->formatValue($row['meta'][$key] ?? null),
        };
    }

    private function progressBar(int $processed, int $total, int $progressBarWidth): string
    {
        $ratio = $total === 0 ? 1 : min($processed / $total, 1);
        $filled = (int) round($progressBarWidth * $ratio);

        return '[' . str_repeat('▓', $filled) . str_repeat('░', max($progressBarWidth - $filled, 0)) . ']';
    }

    private function summaryRow(array $rows, array $session): array
    {
        $meta = [];

        foreach ($rows as $row) {
            foreach (($row['meta'] ?? []) as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    $meta[$key] = (int) ($meta[$key] ?? 0) + (int) $value;

                    continue;
                }

                if (Value::blank($meta[$key] ?? null) && Value::filled($value)) {
                    $meta[$key] = $value;
                }
            }
        }

        return [
            'state' => '',
            'label' => 'TOTAL',
            'processed' => (int) ($session['processed'] ?? 0),
            'total' => (int) ($session['total'] ?? 0),
            'tasks_completed' => array_sum(array_map(fn (array $row): int => (int) ($row['tasks_completed'] ?? 0), $rows)),
            'tasks_total' => array_sum(array_map(fn (array $row): int => (int) ($row['tasks_total'] ?? 0), $rows)),
            'meta' => $meta,
        ];
    }

    private function rowStyle(array $row): string
    {
        if (($row['failed'] ?? false) === true) {
            return 'fg=red';
        }

        if (($row['completed'] ?? false) === true) {
            return 'fg=green';
        }

        return 'fg=gray';
    }

    private function rowIsActive(array $row): bool
    {
        if (($row['failed'] ?? false) === true || ($row['completed'] ?? false) === true) {
            return true;
        }

        return ((int) ($row['processed'] ?? 0)) > 0
            || ((int) ($row['tasks_completed'] ?? 0)) > 0;
    }

    private function stateCell(array $row): string
    {
        if (array_key_exists('state', $row)) {
            return (string) $row['state'];
        }

        if (($row['failed'] ?? false) === true) {
            return '●';
        }

        if (($row['completed'] ?? false) === true) {
            return '●';
        }

        if (((int) ($row['processed'] ?? 0)) > 0 || ((int) ($row['tasks_completed'] ?? 0)) > 0) {
            return '●';
        }

        return '·';
    }

    private function columnIndex(string $key): ?int
    {
        foreach ($this->columns as $index => $column) {
            if (($column['key'] ?? null) === $key) {
                return $index + 1;
            }
        }

        return null;
    }

    private function formatValue(mixed $value): string
    {
        if (Value::blank($value)) {
            return '-';
        }

        if (is_int($value) || is_float($value)) {
            return number_format($value);
        }

        return TerminalSanitizer::text($value);
    }

    private function formatEta(mixed $seconds): string
    {
        if ($seconds === null) {
            return '--:--:--';
        }

        return $this->formatDuration((int) $seconds);
    }

    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return str_pad((string) $hours, 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string) $remainingSeconds, 2, '0', STR_PAD_LEFT);
    }

    private function layoutRows(array $columns, array $rows): array
    {
        $headerCells = array_map(
            fn (array $column): string => (string) ($column['label'] ?? ''),
            $columns,
        );

        return [
            $headerCells,
            ...array_map(fn (array $row): array => $row['cells'], $rows),
        ];
    }

    private function resolvedColumns(array $columns, array $widths): array
    {
        return array_map(
            fn (array $column, int $index): array => [...$column, 'width' => (int) ($widths[$index] ?? 1)],
            $columns,
            array_keys($columns),
        );
    }

    private function stabilizeWidths(array $columns, array $widths, int $availableWidth): array
    {
        foreach ($columns as $index => $column) {
            if (isset($column['width'])) {
                $this->stickyWidths[$index] = (int) $column['width'];

                continue;
            }

            $this->stickyWidths[$index] = max(
                (int) ($this->stickyWidths[$index] ?? 0),
                (int) ($widths[$index] ?? 1),
            );
        }

        return $this->layout->resolve(
            columns: $columns,
            rows: [],
            availableWidth: $availableWidth,
            precomputedWidths: $this->stickyWidths,
        );
    }
}
