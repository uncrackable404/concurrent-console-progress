<?php

namespace Uncrackable404\ConcurrentConsoleProgress\State;

use Closure;
use Uncrackable404\ConcurrentConsoleProgress\ProgressState;

class InMemoryProgressState implements ProgressState
{
    private array $rows;

    private array $global;

    public function __construct(
        array &$rows,
        array &$global,
        private ?Closure $onChange = null,
    ) {
        $this->rows = &$rows;
        $this->global = &$global;
    }

    public function get(string $key, ?string $queue = null): mixed
    {
        if ($queue === null) {
            return $this->global[$key] ?? null;
        }

        return $this->rows[$queue]['meta'][$key] ?? null;
    }

    public function set(string $key, mixed $value, ?string $queue = null): void
    {
        $current = $this->get($key, $queue);

        if ($current === $value) {
            return;
        }

        $this->write($key, $value, $queue);
        $this->notifyChange();
    }

    public function compareAndSet(string $key, mixed $old, mixed $new, ?string $queue = null): bool
    {
        $current = $this->get($key, $queue);

        if ($current !== $old) {
            return false;
        }

        if ($new !== $current) {
            $this->write($key, $new, $queue);
            $this->notifyChange();
        }

        return true;
    }

    public function transform(string $key, Closure $fn, ?string $queue = null): mixed
    {
        $current = $this->get($key, $queue);
        $new = $fn($current);

        if ($new === $current) {
            return $new;
        }

        $this->write($key, $new, $queue);
        $this->notifyChange();

        return $new;
    }

    public function advance(string $queue, int $steps = 1): void
    {
        if ($steps === 0 || ! isset($this->rows[$queue])) {
            return;
        }

        $total = (int) ($this->rows[$queue]['total'] ?? 0);
        $current = (int) ($this->rows[$queue]['processed'] ?? 0);
        $next = $total > 0 ? min($current + $steps, $total) : $current + $steps;

        if ($next === $current) {
            return;
        }

        $this->rows[$queue]['processed'] = $next;
        $this->notifyChange();
    }

    public function increment(string $key, int|float $delta = 1, ?string $queue = null): int|float
    {
        $current = $this->get($key, $queue);
        $base = is_int($delta) ? (int) ($current ?? 0) : (float) ($current ?? 0);
        $new = $base + $delta;

        if ($new === $current) {
            return $new;
        }

        $this->write($key, $new, $queue);
        $this->notifyChange();

        return $new;
    }

    private function write(string $key, mixed $value, ?string $queue): void
    {
        if ($queue === null) {
            $this->global[$key] = $value;

            return;
        }

        if (isset($this->rows[$queue])) {
            $this->rows[$queue]['meta'][$key] = $value;
        }
    }

    private function notifyChange(): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)();
        }
    }
}
