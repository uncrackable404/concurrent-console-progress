<?php

use Uncrackable404\ConcurrentConsoleProgress\State\ParentStateServer;
use Uncrackable404\ConcurrentConsoleProgress\State\RpcProgressState;

function makeRpcTestSocketPath(): string
{
    return sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'ccp-test-rpc-' . getmypid() . '-' . uniqid('', true) . '.sock';
}

function withRpcServer(callable $callback): void
{
    $socketPath = makeRpcTestSocketPath();
    $rows = [];
    $global = [];

    $server = new ParentStateServer(
        $socketPath,
        $rows,
        $global,
        onChange: fn () => null,
    );
    $server->listen();

    pcntl_async_signals(true);
    pcntl_signal(SIGUSR1, function () use ($server): void {
        $server->service();
    });

    try {
        $callback($socketPath, $server, $rows, $global);
    } finally {
        pcntl_signal(SIGUSR1, SIG_DFL);
        $server->shutdown();
    }
}

it('sends GET requests and receives values', function () {
    withRpcServer(function (string $socketPath, ParentStateServer $server, array &$rows, array &$global): void {
        $global['credits'] = 8000;

        $state = new RpcProgressState($socketPath, getmypid());

        expect($state->get('credits'))->toBe(8000);
        expect($state->get('missing'))->toBeNull();
    });
});

it('sends SET requests and mutates parent state', function () {
    withRpcServer(function (string $socketPath, ParentStateServer $server, array &$rows, array &$global): void {
        $state = new RpcProgressState($socketPath, getmypid());

        $state->set('credits', 7500);

        expect($global['credits'])->toBe(7500);
    });
});

it('returns true/false for successful and failing CAS', function () {
    withRpcServer(function (string $socketPath, ParentStateServer $server, array &$rows, array &$global): void {
        $global['credits'] = 9000;
        $state = new RpcProgressState($socketPath, getmypid());

        expect($state->compareAndSet('credits', 9000, 8000))->toBeTrue();
        expect($global['credits'])->toBe(8000);

        expect($state->compareAndSet('credits', 9999, 5000))->toBeFalse();
        expect($global['credits'])->toBe(8000);
    });
});

it('transforms via CAS loop with min aggregation', function () {
    withRpcServer(function (string $socketPath, ParentStateServer $server, array &$rows, array &$global): void {
        $state = new RpcProgressState($socketPath, getmypid());

        $minOf = fn (int $value): Closure => fn (?int $current): int => $current === null ? $value : min($current, $value);

        $state->transform('credits', $minOf(9000));
        $state->transform('credits', $minOf(7500));
        $state->transform('credits', $minOf(8500));

        expect($global['credits'])->toBe(7500);
    });
});

it('shortcuts transform when new value equals current', function () {
    withRpcServer(function (string $socketPath, ParentStateServer $server, array &$rows, array &$global): void {
        $state = new RpcProgressState($socketPath, getmypid());
        $state->set('credits', 5000);

        $result = $state->transform('credits', fn (mixed $current): int => (int) $current);

        expect($result)->toBe(5000);
        expect($global['credits'])->toBe(5000);
    });
});

it('advances queue processed counter via RPC', function () {
    withRpcServer(function (string $socketPath, ParentStateServer $server, array &$rows, array &$global): void {
        $rows['api'] = [
            'label' => 'api',
            'total' => 100,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 1,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ];

        $state = new RpcProgressState($socketPath, getmypid());

        $state->advance('api', 10);
        $state->advance('api', 40);

        expect($rows['api']['processed'])->toBe(50);

        // Clamped to total.
        $state->advance('api', 500);
        expect($rows['api']['processed'])->toBe(100);
    });
});

it('increments numeric counters atomically via RPC', function () {
    withRpcServer(function (string $socketPath, ParentStateServer $server, array &$rows, array &$global): void {
        $state = new RpcProgressState($socketPath, getmypid());

        expect($state->increment('retries'))->toBe(1);
        expect($state->increment('retries', 4))->toBe(5);
        expect($global['retries'])->toBe(5);

        $rows['api'] = [
            'label' => 'api',
            'total' => 10,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 1,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ];

        expect($state->increment('updated', 3, queue: 'api'))->toBe(3);
        expect($rows['api']['meta']['updated'])->toBe(3);
    });
});

it('supports per-queue writes via the queue parameter', function () {
    withRpcServer(function (string $socketPath, ParentStateServer $server, array &$rows, array &$global): void {
        $rows['cars'] = [
            'label' => 'cars',
            'total' => 10,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 1,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ];

        $state = new RpcProgressState($socketPath, getmypid());
        $state->set('updated', 5, 'cars');

        expect($rows['cars']['meta']['updated'])->toBe(5);
        expect($state->get('updated', 'cars'))->toBe(5);
    });
});
