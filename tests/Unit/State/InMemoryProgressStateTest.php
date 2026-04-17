<?php

use Uncrackable404\ConcurrentConsoleProgress\State\InMemoryProgressState;

function makeInMemoryRowsAndGlobal(): array
{
    $rows = [
        'cars' => [
            'label' => 'cars',
            'total' => 100,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 1,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
    ];
    $global = [];

    return [$rows, $global];
}

it('returns null when key is not set', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();
    $state = new InMemoryProgressState($rows, $global);

    expect($state->get('credits'))->toBeNull();
});

it('writes via set and reflects in the global array', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();
    $state = new InMemoryProgressState($rows, $global);

    $state->set('credits', 8000);

    expect($state->get('credits'))->toBe(8000)
        ->and($global['credits'])->toBe(8000);
});

it('writes per-queue meta', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();
    $state = new InMemoryProgressState($rows, $global);

    $state->set('updated', 5, 'cars');

    expect($state->get('updated', 'cars'))->toBe(5)
        ->and($rows['cars']['meta']['updated'])->toBe(5);
});

it('ignores per-queue writes to unknown queues', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();
    $state = new InMemoryProgressState($rows, $global);

    $state->set('updated', 5, 'unknown');

    expect($state->get('updated', 'unknown'))->toBeNull()
        ->and($rows)->not->toHaveKey('unknown');
});

it('transforms the current value', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();
    $state = new InMemoryProgressState($rows, $global);

    $state->set('credits', 9000);

    $result = $state->transform('credits', fn (?int $current): int => min((int) $current, 7500));

    expect($result)->toBe(7500)
        ->and($global['credits'])->toBe(7500);
});

it('passes null to the closure when key is missing and sets the returned value', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();
    $state = new InMemoryProgressState($rows, $global);

    $result = $state->transform(
        'credits',
        fn (?int $current): int => $current === null ? 5000 : min($current, 5000),
    );

    expect($result)->toBe(5000)
        ->and($global['credits'])->toBe(5000);
});

it('compare-and-set only when current equals old', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();
    $state = new InMemoryProgressState($rows, $global);

    $state->set('credits', 8000);

    expect($state->compareAndSet('credits', 8000, 7500))->toBeTrue()
        ->and($global['credits'])->toBe(7500);

    expect($state->compareAndSet('credits', 8000, 6000))->toBeFalse()
        ->and($global['credits'])->toBe(7500);
});

it('advances the processed counter of a queue clamped to total', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();
    $state = new InMemoryProgressState($rows, $global);

    $state->advance('cars', 30);
    expect($rows['cars']['processed'])->toBe(30);

    $state->advance('cars', 200); // would exceed total=100
    expect($rows['cars']['processed'])->toBe(100);

    // Unknown queue: no-op, no crash
    $state->advance('unknown', 5);
    expect($rows)->not->toHaveKey('unknown');
});

it('increments atomic numeric counters on global and per-queue scope', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();
    $state = new InMemoryProgressState($rows, $global);

    expect($state->increment('retries'))->toBe(1);
    expect($state->increment('retries', 4))->toBe(5);
    expect($global['retries'])->toBe(5);

    expect($state->increment('updated', 2, queue: 'cars'))->toBe(2);
    expect($rows['cars']['meta']['updated'])->toBe(2);
});

it('supports float increments on global scope', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();
    $state = new InMemoryProgressState($rows, $global);

    expect($state->increment('bytes_per_ms', 0.5))->toBe(0.5);
    expect($state->increment('bytes_per_ms', 0.25))->toBe(0.75);
});

it('triggers onChange after set and transform mutations', function () {
    [$rows, $global] = makeInMemoryRowsAndGlobal();

    $changes = 0;
    $state = new InMemoryProgressState(
        $rows,
        $global,
        onChange: function () use (&$changes): void {
            $changes++;
        },
    );

    $state->set('credits', 9000);
    $state->set('credits', 9000); // no-op: same value
    $state->transform('credits', fn (int $c): int => $c - 500);
    $state->transform('credits', fn (int $c): int => $c); // no-op
    $state->compareAndSet('credits', 8500, 8000);
    $state->compareAndSet('credits', 9999, 7000); // no-op: wrong old

    expect($changes)->toBe(3);
});
