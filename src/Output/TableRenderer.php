<?php

namespace Uncrackable404\ConcurrentConsoleProgress\Output;

use Symfony\Component\Console\Terminal;
use Uncrackable404\ConcurrentConsoleProgress\Support\TerminalSanitizer;
use Uncrackable404\ConcurrentConsoleProgress\Support\Value;

class TableRenderer
{
    private const CELL_SPACING = '  ';

    public function __construct(
        private array $columns,
        private ColumnLayout $layout = new ColumnLayout,
        private Terminal $terminal = new Terminal,
    ) {}

    public function render(array $rows): string
    {
        $headerCells = $this->headerCells();
        $rowCells = array_map(fn (array $row): array => $row['cells'], $rows);
        $availableWidth = max($this->terminal->getWidth(), 20);
        $widths = $this->layout->resolve($this->columns, [$headerCells, ...$rowCells], $availableWidth);

        $lines = ['<options=bold>' . $this->renderLine($headerCells, $widths) . '</>'];

        foreach ($rows as $row) {
            $line = $this->renderLine($row['cells'], $widths);

            if (Value::filled($row['style'] ?? null)) {
                $line = '<' . $row['style'] . '>' . $line . '</>';
            }

            $lines[] = $line;
        }

        return implode(PHP_EOL, $lines);
    }

    private function headerCells(): array
    {
        return array_map(fn (array $column): string => (string) ($column['label'] ?? ''), $this->columns);
    }

    private function renderLine(array $cells, array $widths): string
    {
        return $this->renderCells($cells, $widths);
    }

    private function renderCells(array $cells, array $widths): string
    {
        $rendered = [];

        foreach ($cells as $index => $value) {
            $rendered[] = $this->fit(
                value: (string) $value,
                width: (int) ($widths[$index] ?? ColumnLayout::width((string) $value)),
                align: (string) ($this->columns[$index]['align'] ?? 'left'),
            );
        }

        return implode(self::CELL_SPACING, $rendered);
    }

    private function fit(string $value, int $width, string $align): string
    {
        $visibleWidth = ColumnLayout::width($value);

        if ($visibleWidth > $width) {
            $value = $this->trimToWidth($value, $width);
            $visibleWidth = ColumnLayout::width($value);
        }

        $padding = max($width - $visibleWidth, 0);

        return $align === 'right'
            ? str_repeat(' ', $padding) . $value
            : $value . str_repeat(' ', $padding);
    }

    private function trimToWidth(string $value, int $width): string
    {
        return TerminalSanitizer::trimVisibleText($value, $width);
    }

}
