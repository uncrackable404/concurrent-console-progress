<?php

namespace Uncrackable404\ConcurrentConsoleProgress;

use Closure;
use Symfony\Component\Console\Cursor;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Uncrackable404\ConcurrentConsoleProgress\Exceptions\ChildProcessException;
use Uncrackable404\ConcurrentConsoleProgress\Output\ProgressTableRenderer;
use Uncrackable404\ConcurrentConsoleProgress\Support\FailFastFork;
use Uncrackable404\ConcurrentConsoleProgress\Support\Value;

class ConcurrentProgress
{
    private bool $canRender = false;

    private array $lastFrame = [];

    private float $startedAt = 0;

    private float $lastRenderedAt = 0;

    private float $minSecondsBetweenRedraws = 0.1;

    private ProgressTableRenderer $renderer;

    private ?Cursor $cursor = null;

    private int $renderedLinesCount = 0;

    private static ?OutputInterface $output = null;

    public static function setOutput(?OutputInterface $output): void
    {
        static::$output = $output;
    }

    protected static function output(): OutputInterface
    {
        return static::$output ??= new ConsoleOutput;
    }

    public function run(
        array $queues,
        array $tasks,
        int $concurrent,
        Closure $process,
        array $columns = [],
        array $footer = [],
        ?Closure $before = null,
    ): array {
        if ($tasks === []) {
            return [];
        }

        $columns = $this->normalizeColumns($columns, $queues, $tasks);
        $footer = $this->normalizeFooter($footer);
        $this->renderer = new ProgressTableRenderer($columns, $footer);

        if ($concurrent < 1) {
            return $this->runSynchronously($queues, $tasks, $process, $before);
        }

        $rows = $this->makeRows($queues, $tasks);
        $global = [];

        $this->startLayout($rows, $global);

        $fork = (new FailFastFork)
            ->concurrent(max(1, $concurrent))
            ->before($before);

        $fork->after(
            parent: function (array $result) use (&$rows, &$global, $fork): void {
                $failureReason = $this->applyResultAndRender($rows, $global, $result);

                if ($failureReason !== null) {
                    $fork->fail($failureReason);
                }
            },
        );

        $results = $fork->run(
            ...array_map(
                fn (array $task): Closure => $this->wrapTask($task, $process),
                $tasks,
            )
        );

        foreach ($rows as &$row) {
            $row['completed'] = ((int) $row['tasks_completed']) >= ((int) $row['tasks_total']);
        }

        unset($row);

        $this->updateLayout($rows, $global, true);

        return $results;
    }

    private function runSynchronously(
        array $queues,
        array $tasks,
        Closure $process,
        ?Closure $before,
    ): array {
        $rows = $this->makeRows($queues, $tasks);
        $global = [];

        $this->startLayout($rows, $global);

        $results = [];

        foreach ($tasks as $task) {
            if ($before !== null) {
                $before();
            }

            $result = $this->wrapTask($task, $process)();
            $results[] = $result;

            $failureReason = $this->applyResultAndRender($rows, $global, $result);

            if ($failureReason !== null) {
                throw $failureReason;
            }
        }

        foreach ($rows as &$row) {
            $row['completed'] = ((int) $row['tasks_completed']) >= ((int) $row['tasks_total']);
        }

        unset($row);

        $this->updateLayout($rows, $global, true);

        return $results;
    }

    private function wrapTask(array $task, Closure $process): Closure
    {
        return function () use ($task, $process): array {
            try {
                $result = $process($task);

                return [
                    'queue' => $task['queue'],
                    'steps' => (int) $task['steps'],
                    'advance' => (int) ($result['advance'] ?? $result['processed'] ?? $task['steps']),
                    'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : ($result['row_meta'] ?? []),
                    'global' => is_array($result['global'] ?? null) ? $result['global'] : ($result['global_meta'] ?? []),
                    'error_context' => $task['error_context'] ?? null,
                    'failed' => false,
                ];
            } catch (Throwable $exception) {
                return [
                    'queue' => $task['queue'],
                    'steps' => (int) $task['steps'],
                    'advance' => 0,
                    'meta' => [],
                    'global' => [],
                    'error_context' => $task['error_context'] ?? null,
                    'failed' => true,
                    'exception' => ChildProcessException::snapshotException($task, $exception),
                ];
            }
        };
    }

    private function normalizeColumns(array $columns, array $queues, array $tasks): array
    {
        $columns = $this->mergeDefinitions(
            $this->defaultColumns($queues, $tasks),
            $columns,
        );

        return array_map(
            fn (array $column): array => array_filter([
                'key' => $column['key'],
                'label' => $column['label'],
                'align' => $column['align'] ?? 'left',
                'width' => $column['width'] ?? null,
                'min_width' => $column['min_width'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            $columns,
        );
    }

    private function defaultColumns(array $queues, array $tasks): array
    {
        $columns = [
            ['key' => 'label', 'label' => 'QUEUE'],
            ['key' => 'progress', 'label' => 'PROGRESS'],
            ['key' => 'percent', 'label' => '%', 'align' => 'right'],
            ['key' => 'tasks', 'label' => 'TASKS', 'align' => 'right'],
        ];

        if ($this->processedColumnIsRedundant($queues, $tasks) === false) {
            array_splice($columns, 3, 0, [[
                'key' => 'processed',
                'label' => 'PROCESSED',
                'align' => 'right',
            ]]);
        }

        return $columns;
    }

    private function processedColumnIsRedundant(array $queues, array $tasks): bool
    {
        $queueTaskCounts = [];
        $queueStepTotals = [];

        foreach ($tasks as $task) {
            $queue = (string) ($task['queue'] ?? '');
            $steps = (int) ($task['steps'] ?? 0);

            $queueTaskCounts[$queue] = (int) ($queueTaskCounts[$queue] ?? 0) + 1;
            $queueStepTotals[$queue] = (int) ($queueStepTotals[$queue] ?? 0) + $steps;
        }

        foreach ($queueTaskCounts as $queue => $taskCount) {
            if (($queueStepTotals[$queue] ?? 0) !== $taskCount) {
                return false;
            }
        }

        foreach ($queues as $queue => $definition) {
            $total = (int) ($definition['total'] ?? 0);
            $taskCount = (int) ($queueTaskCounts[$queue] ?? 0);

            if ($total !== $taskCount) {
                return false;
            }
        }

        return true;
    }

    private function normalizeFooter(array $footer): array
    {
        $footer = $this->mergeDefinitions($this->defaultFooter(), $footer);

        return array_map(
            fn (array $item): array => [
                'key' => $item['key'],
                'label' => $item['label'],
                'align' => $item['align'] ?? 'left',
            ],
            $footer,
        );
    }

    private function defaultFooter(): array
    {
        return [
            ['key' => 'elapsed', 'label' => 'ELAPSED'],
            ['key' => 'eta', 'label' => 'ETA'],
        ];
    }

    private function mergeDefinitions(array $defaults, array $custom): array
    {
        if ($custom === []) {
            return $defaults;
        }

        $merged = [];
        $customByKey = [];

        foreach ($custom as $definition) {
            $key = (string) ($definition['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $customByKey[$key] = $definition;
        }

        foreach ($defaults as $definition) {
            $key = (string) ($definition['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $merged[] = array_merge($definition, $customByKey[$key] ?? []);
            unset($customByKey[$key]);
        }

        foreach ($custom as $definition) {
            $key = (string) ($definition['key'] ?? '');

            if ($key === '' || array_key_exists($key, $customByKey) === false) {
                continue;
            }

            $merged[] = $customByKey[$key];
            unset($customByKey[$key]);
        }

        return $merged;
    }

    private function makeRows(array $queues, array $tasks): array
    {
        $rows = [];

        foreach ($queues as $key => $queue) {
            $rows[$key] = [
                'label' => $queue['label'],
                'total' => (int) $queue['total'],
                'processed' => 0,
                'tasks_completed' => 0,
                'tasks_total' => 0,
                'meta' => [],
                'failed' => false,
                'completed' => false,
            ];
        }

        foreach ($tasks as $task) {
            $key = $task['queue'];

            if (array_key_exists($key, $rows) === false) {
                $rows[$key] = [
                    'label' => $key,
                    'total' => 0,
                    'processed' => 0,
                    'tasks_completed' => 0,
                    'tasks_total' => 0,
                    'meta' => [],
                    'failed' => false,
                    'completed' => false,
                ];
            }

            if (array_key_exists($key, $queues) === false) {
                $rows[$key]['total'] += (int) $task['steps'];
            }

            $rows[$key]['tasks_total']++;
        }

        return $rows;
    }

    private function applyResult(array &$rows, array &$global, array $result): void
    {
        $key = (string) $result['queue'];

        if (array_key_exists($key, $rows) === false) {
            return;
        }

        $rows[$key]['processed'] += min(
            (int) ($result['advance'] ?? 0),
            (int) ($result['steps'] ?? 0),
        );
        $rows[$key]['tasks_completed']++;
        $rows[$key]['failed'] = $rows[$key]['failed'] || (($result['failed'] ?? false) === true);
        $rows[$key]['completed'] = $rows[$key]['tasks_completed'] >= $rows[$key]['tasks_total'];

        $this->mergeRowMeta($rows[$key]['meta'], is_array($result['meta'] ?? null) ? $result['meta'] : []);
        $this->mergeGlobal($global, is_array($result['global'] ?? null) ? $result['global'] : []);
    }

    private function applyResultAndRender(array &$rows, array &$global, array $result): ?Throwable
    {
        $this->applyResult($rows, $global, $result);

        if (($result['failed'] ?? false) === true) {
            $this->updateLayout($rows, $global, forceRedraw: true);

            return ChildProcessException::fromResult($result);
        }

        $this->updateLayout($rows, $global);

        return null;
    }

    private function mergeRowMeta(array &$current, array $incoming): void
    {
        foreach ($incoming as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $current[$key] = (int) ($current[$key] ?? 0) + (int) $value;

                continue;
            }

            if (Value::filled($value)) {
                $current[$key] = $value;
            }
        }
    }

    private function mergeGlobal(array &$current, array $incoming): void
    {
        foreach ($incoming as $key => $value) {
            if (Value::filled($value)) {
                $current[$key] = $value;
            }
        }
    }

    private function startLayout(array $rows, array $global): void
    {
        $this->canRender = static::output()->isDecorated();

        if ($this->canRender === false) {
            $this->cursor = null;

            return;
        }

        $this->startedAt = microtime(true);
        $this->lastRenderedAt = 0;
        $this->cursor = new Cursor(static::output());
        $this->renderFullLayout($this->frameLines($this->renderLayout($rows, $global)));
    }

    private function updateLayout(
        array $rows,
        array $global,
        bool $completed = false,
        bool $forceRedraw = false,
    ): void {
        if ($this->canRender === false) {
            return;
        }

        $frame = $this->frameLines($this->renderLayout($rows, $global, $completed));

        if ($forceRedraw === false && $completed === false && $this->shouldSkipRedraw($frame)) {
            return;
        }

        $this->overwriteLayout($frame);
    }

    private function renderLayout(array $rows, array $global, bool $completed = false): string
    {
        $elapsed = max((int) floor(microtime(true) - $this->startedAt), 0);
        $total = array_sum(array_map(fn (array $row): int => (int) $row['total'], $rows));
        $processed = array_sum(array_map(
            fn (array $row): int => min((int) ($row['processed'] ?? 0), (int) $row['total']),
            $rows,
        ));
        $eta = $this->estimateEta($processed, $total, $elapsed, $completed);

        return $this->renderer->render(
            rows: $rows,
            global: $global,
            session: [
                'processed' => $processed,
                'total' => $total,
                'elapsed' => $elapsed,
                'eta' => $eta,
                'completed' => $completed,
            ],
        );
    }

    private function overwriteLayout(array $lines): void
    {
        if ($lines === $this->lastFrame || $this->cursor === null) {
            return;
        }

        $this->renderFullLayout($lines);
    }

    private function frameLines(string $frame): array
    {
        return explode(PHP_EOL, $frame);
    }

    private function shouldSkipRedraw(array $frame): bool
    {
        if ($frame === $this->lastFrame) {
            return true;
        }

        return (microtime(true) - $this->lastRenderedAt) < $this->minSecondsBetweenRedraws;
    }

    private function renderFullLayout(array $lines): void
    {
        if ($this->cursor !== null && $this->renderedLinesCount > 0) {
            $this->cursor
                ->moveUp($this->renderedLinesCount)
                ->moveToColumn(1)
                ->clearOutput();
        }

        static::output()->writeln($lines);
        $this->lastFrame = $lines;
        $this->renderedLinesCount = count($lines);
        $this->lastRenderedAt = microtime(true);
    }

    private function estimateEta(int $processed, int $total, int $elapsed, bool $completed = false): ?int
    {
        if ($completed || $total === 0 || $processed >= $total) {
            return 0;
        }

        if ($processed === 0 || $elapsed <= 0) {
            return null;
        }

        $itemsPerSecond = $processed / $elapsed;

        if ($itemsPerSecond <= 0) {
            return null;
        }

        return (int) ceil(($total - $processed) / $itemsPerSecond);
    }
}
