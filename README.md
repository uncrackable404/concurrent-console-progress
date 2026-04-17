# Concurrent Console Progress

![Concurrent Console Progress Preview](art/preview.png)

[![Total Downloads](https://img.shields.io/packagist/dt/uncrackable404/concurrent-console-progress)](https://packagist.org/packages/uncrackable404/concurrent-console-progress)
[![Latest Stable Version](https://img.shields.io/packagist/v/uncrackable404/concurrent-console-progress)](https://packagist.org/packages/uncrackable404/concurrent-console-progress)
[![License](https://img.shields.io/packagist/l/uncrackable404/concurrent-console-progress)](https://packagist.org/packages/uncrackable404/concurrent-console-progress)

## Introduction

**Concurrent Console Progress** is a live dashboard for PHP console applications that run many concurrent tasks across one or more queues. Typical use cases: bulk imports, fan-out API calls, background migrations, and any batch job where you want per-queue progress bars, aggregated counters, and atomic shared state updated in real time.

The package is powered by [**spatie/fork**](https://github.com/spatie/fork) for process isolation and inspired by [**laravel/prompts**](https://github.com/laravel/prompts) for terminal rendering.

## Requirements

- PHP `^8.3`
- `ext-pcntl`, `ext-posix` (Unix-only — macOS, Linux)
- Works in CLI context only (forking is not available in FPM/Apache)

## Installation

```bash
composer require uncrackable404/concurrent-console-progress
```

## Usage

The package exposes:

- `Uncrackable404\ConcurrentConsoleProgress\ConcurrentProgress` — the main class.
- `Uncrackable404\ConcurrentConsoleProgress\concurrent()` — a convenience helper around `ConcurrentProgress::run()`.
- `Uncrackable404\ConcurrentConsoleProgress\ProgressState` — the interface for the shared state your tasks can read and mutate.

### Minimal Example

One queue, one counter, no shared state:

```php
use Uncrackable404\ConcurrentConsoleProgress\ProgressState;

use function Uncrackable404\ConcurrentConsoleProgress\concurrent;

$tasks = [];
for ($i = 1; $i <= 50; $i++) {
    $tasks[] = ['queue' => 'main', 'steps' => 1, 'id' => $i];
}

concurrent(
    queues: ['main' => ['label' => 'Processing', 'total' => count($tasks)]],
    tasks: $tasks,
    concurrent: 10,
    process: function (array $task, ProgressState $state): void {
        usleep(100_000);
        $state->advance('main', 1);
    },
);
```

### Complete Example

Multiple queues, custom columns, footer values, and free-form return values. Task state (progress, per-row counters, global footer values) flows through the `ProgressState` passed as the second argument — the callback's return value is free-form and is forwarded to the caller as-is.

```php
use Uncrackable404\ConcurrentConsoleProgress\ConcurrentProgress;
use Uncrackable404\ConcurrentConsoleProgress\ProgressState;

$queues = [
    'users' => ['label' => 'Importing Users', 'total' => 2],
    'orders' => ['label' => 'Importing Orders', 'total' => 1],
];

$tasks = [
    ['queue' => 'users', 'steps' => 1, 'data' => ['id' => 1, 'name' => 'John']],
    ['queue' => 'users', 'steps' => 1, 'data' => ['id' => 2, 'name' => 'Jane']],
    ['queue' => 'orders', 'steps' => 1, 'data' => ['id' => 101, 'total' => 50]],
];

$progress = new ConcurrentProgress();
$results = $progress->run(
    queues: $queues,
    tasks: $tasks,
    concurrent: 5,
    columns: [
        ['key' => 'status', 'label' => 'LAST ITEM'],
    ],
    footer: [
        ['key' => 'memory_peak', 'label' => 'MEMORY PEAK'],
    ],
    process: function (array $task, ProgressState $state): array {
        $id = $task['data']['id'] ?? 'unknown';

        // Per-queue cell (shown in the `status` column).
        $state->set('status', "✅ Processed #{$id}", queue: $task['queue']);

        // Global footer value — update atomically.
        $state->transform(
            'memory_peak',
            fn (mixed $current): int => max((int) ($current ?? 0), memory_get_peak_usage(true)),
        );

        // Advance the progress bar by one step for this queue.
        $state->advance($task['queue'], 1);

        // The return value is free-form and ends up in the $results array.
        return ['id' => $id];
    }
);

// $results = [['id' => 1], ['id' => 2], ['id' => 101]]
```

### Progress State API

The `ProgressState` interface exposes atomic read/write operations against state owned by the parent process. A `ProgressState` instance is injected into your task callback when the closure signature declares a second parameter:

```php
interface ProgressState
{
    public function get(string $key, ?string $queue = null): mixed;
    public function set(string $key, mixed $value, ?string $queue = null): void;
    public function compareAndSet(string $key, mixed $old, mixed $new, ?string $queue = null): bool;
    public function transform(string $key, Closure $fn, ?string $queue = null): mixed;
    public function advance(string $queue, int $steps = 1): void;
    public function increment(string $key, int|float $delta = 1, ?string $queue = null): int|float;
}
```

- `advance()` increments the queue's `processed` counter (clamped to `total`) — this is what drives the progress bar.
- `increment()` is an atomic numeric counter over `$global[$key]` (when `$queue === null`) or `$rows[$queue]['meta'][$key]`.
- `transform()` applies a closure atomically via a compare-and-set retry loop. The closure receives the current value (or `null` if unset) and returns the new value. Ideal for `min` / `max` aggregations where the result depends on the current value.
- `get()` returns the stored value or `null`. Use the null-coalescing operator for defaults: `$state->get('credits') ?? 0`.
- `set()` / `compareAndSet()` are the primitives backing the higher-level helpers.

When `concurrent >= 1` the state lives in the parent process and child tasks communicate with it via a Unix-socket RPC (no state files, no extra extensions). When `concurrent: 0` the state lives in memory of the single process.

Back-compat: the second parameter is optional. Single-argument closures `fn (array $task) => …` still work — you just don't get access to `ProgressState`.

## Laravel Integration

The package ships with `ConsoleProgressServiceProvider`, auto-discovered by Laravel. It wires the command output into the dashboard, so you can use `concurrent()` inside any `Artisan` command with no extra configuration:

```php
use Illuminate\Console\Command;
use Uncrackable404\ConcurrentConsoleProgress\ProgressState;

use function Uncrackable404\ConcurrentConsoleProgress\concurrent;

class ImportUsers extends Command
{
    protected $signature = 'users:import';

    public function handle(): int
    {
        $tasks = User::cursor()->map(fn ($user) => [
            'queue' => 'users',
            'steps' => 1,
            'id' => $user->id,
        ])->all();

        concurrent(
            queues: ['users' => ['label' => 'Users', 'total' => count($tasks)]],
            tasks: $tasks,
            concurrent: 8,
            process: function (array $task, ProgressState $state): void {
                // your work here
                $state->advance('users', 1);
            },
        );

        return self::SUCCESS;
    }
}
```

## Synchronous Mode

Set `concurrent: 0` to run all tasks sequentially in the same process, bypassing `spatie/fork` entirely. The rendering, state, and fail-fast logic are unchanged — only the execution is serial. Useful when debugging with `dd()`, `dump()`, or `xdebug`:

```php
use Uncrackable404\ConcurrentConsoleProgress\ProgressState;

use function Uncrackable404\ConcurrentConsoleProgress\concurrent;

concurrent(
    queues: ['main' => ['label' => 'Processing', 'total' => 1]],
    tasks: [['queue' => 'main', 'steps' => 1]],
    concurrent: 0,
    process: function (array $task, ProgressState $state): void {
        dd($task); // works — same process, no fork
        $state->advance('main', 1);
    },
);
```

| `concurrent` | Behavior |
|---|---|
| `0` | Synchronous — no fork, same process |
| `1` | Forked — one child process at a time |
| `N` | Forked — up to N child processes in parallel |

## How It Works

- **Process isolation.** Each task runs in its own child process via `pcntl_fork` (through `spatie/fork`). A crash in one task does not affect the others, and memory leaks are bounded because every child exits after completion.
- **Parent-owned shared state.** `ProgressState` lives only in the parent. Children talk to it over a Unix-domain socket created in the system temp directory; no state file is ever written to disk. Operations (`get`, `set`, `compareAndSet`, `advance`, …) are synchronous RPC calls.
- **Real-time rendering.** After each state mutation the child wakes the parent with `SIGUSR1`; the parent drains the pending RPC, re-renders the dashboard, and returns. Frame throttling prevents flicker under rapid updates.
- **Atomic aggregations.** `transform()` runs a compare-and-set retry loop against the parent, so `min` / `max` / custom reducers work correctly even with many forked tasks updating the same key concurrently.
- **Fail-fast.** If any task throws, the parent tears down all running children and re-throws a snapshot exception with the original file, line, and trace preserved.

## License

The MIT License (MIT).