<?php

namespace Uncrackable404\ConcurrentConsoleProgress;

use Closure;

interface ProgressState
{
    public function get(string $key, ?string $queue = null): mixed;

    public function set(string $key, mixed $value, ?string $queue = null): void;

    public function compareAndSet(string $key, mixed $old, mixed $new, ?string $queue = null): bool;

    public function transform(string $key, Closure $fn, ?string $queue = null): mixed;

    /**
     * Increment the queue's `processed` counter (progress bar).
     * Values are clamped to the queue's total.
     */
    public function advance(string $queue, int $steps = 1): void;

    /**
     * Atomic numeric increment on a key. Returns the new value.
     *  - $queue === null → operates on the global state.
     *  - $queue !== null → operates on `$rows[$queue]['meta'][$key]`.
     */
    public function increment(string $key, int|float $delta = 1, ?string $queue = null): int|float;
}
