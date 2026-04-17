<?php

use Uncrackable404\ConcurrentConsoleProgress\State\ParentStateServer;

function makeServerSocketPath(): string
{
    return sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'ccp-test-' . getmypid() . '-' . uniqid('', true) . '.sock';
}

function sendRpc(mixed $client, array $request): array
{
    fwrite($client, json_encode($request) . "\n");

    $line = fgets($client);

    expect($line)->toBeString();

    return json_decode($line, true);
}

it('handles GET requests and returns default null for missing keys', function () {
    $socketPath = makeServerSocketPath();
    $rows = [];
    $global = [];

    $server = new ParentStateServer(
        $socketPath,
        $rows,
        $global,
        onChange: fn () => null,
    );
    $server->listen();

    try {
        $client = stream_socket_client('unix://' . $socketPath);
        stream_set_blocking($client, true);

        fwrite($client, json_encode(['op' => 'get', 'id' => 1, 'key' => 'credits', 'queue' => null]) . "\n");

        $server->service();

        $line = fgets($client);
        $response = json_decode($line, true);

        expect($response)->toBe(['id' => 1, 'value' => null]);
        fclose($client);
    } finally {
        $server->shutdown();
    }
});

it('handles SET and GET round-trip', function () {
    $socketPath = makeServerSocketPath();
    $rows = [];
    $global = [];

    $server = new ParentStateServer(
        $socketPath,
        $rows,
        $global,
        onChange: fn () => null,
    );
    $server->listen();

    try {
        $client = stream_socket_client('unix://' . $socketPath);
        stream_set_blocking($client, true);

        fwrite($client, json_encode(['op' => 'set', 'id' => 1, 'key' => 'credits', 'value' => 9000, 'queue' => null]) . "\n");
        $server->service();

        $setResponse = json_decode(fgets($client), true);
        expect($setResponse)->toBe(['id' => 1, 'ok' => true])
            ->and($global['credits'])->toBe(9000);

        fwrite($client, json_encode(['op' => 'get', 'id' => 2, 'key' => 'credits', 'queue' => null]) . "\n");
        $server->service();

        $getResponse = json_decode(fgets($client), true);
        expect($getResponse)->toBe(['id' => 2, 'value' => 9000]);

        fclose($client);
    } finally {
        $server->shutdown();
    }
});

it('handles CAS success and failure', function () {
    $socketPath = makeServerSocketPath();
    $rows = [];
    $global = ['credits' => 9000];

    $server = new ParentStateServer(
        $socketPath,
        $rows,
        $global,
        onChange: fn () => null,
    );
    $server->listen();

    try {
        $client = stream_socket_client('unix://' . $socketPath);
        stream_set_blocking($client, true);

        fwrite($client, json_encode(['op' => 'cas', 'id' => 1, 'key' => 'credits', 'old' => 9000, 'new' => 8000, 'queue' => null]) . "\n");
        $server->service();

        $success = json_decode(fgets($client), true);
        expect($success)->toBe(['id' => 1, 'ok' => true, 'value' => 8000])
            ->and($global['credits'])->toBe(8000);

        fwrite($client, json_encode(['op' => 'cas', 'id' => 2, 'key' => 'credits', 'old' => 9999, 'new' => 7000, 'queue' => null]) . "\n");
        $server->service();

        $failure = json_decode(fgets($client), true);
        expect($failure)->toBe(['id' => 2, 'ok' => false, 'value' => 8000])
            ->and($global['credits'])->toBe(8000);

        fclose($client);
    } finally {
        $server->shutdown();
    }
});

it('writes per-queue meta', function () {
    $socketPath = makeServerSocketPath();
    $rows = [
        'cars' => ['meta' => [], 'label' => 'cars', 'total' => 0, 'processed' => 0, 'tasks_completed' => 0, 'tasks_total' => 0, 'failed' => false, 'completed' => false],
    ];
    $global = [];

    $server = new ParentStateServer(
        $socketPath,
        $rows,
        $global,
        onChange: fn () => null,
    );
    $server->listen();

    try {
        $client = stream_socket_client('unix://' . $socketPath);
        stream_set_blocking($client, true);

        fwrite($client, json_encode(['op' => 'set', 'id' => 1, 'key' => 'updated', 'value' => 7, 'queue' => 'cars']) . "\n");
        $server->service();

        fgets($client);

        expect($rows['cars']['meta']['updated'])->toBe(7);

        fclose($client);
    } finally {
        $server->shutdown();
    }
});

it('advances the queue processed counter clamped to total via RPC', function () {
    $socketPath = makeServerSocketPath();
    $rows = [
        'cars' => [
            'label' => 'cars',
            'total' => 50,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 0,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
    ];
    $global = [];

    $server = new ParentStateServer(
        $socketPath,
        $rows,
        $global,
        onChange: fn () => null,
    );
    $server->listen();

    try {
        $client = stream_socket_client('unix://' . $socketPath);
        stream_set_blocking($client, true);

        fwrite($client, json_encode(['op' => 'advance', 'id' => 1, 'queue' => 'cars', 'steps' => 20]) . "\n");
        $server->service();
        $first = json_decode(fgets($client), true);
        expect($first)->toBe(['id' => 1, 'ok' => true, 'value' => 20])
            ->and($rows['cars']['processed'])->toBe(20);

        // Overshoot is clamped to total.
        fwrite($client, json_encode(['op' => 'advance', 'id' => 2, 'queue' => 'cars', 'steps' => 100]) . "\n");
        $server->service();
        $second = json_decode(fgets($client), true);
        expect($second)->toBe(['id' => 2, 'ok' => true, 'value' => 50])
            ->and($rows['cars']['processed'])->toBe(50);

        // Unknown queue is a safe no-op.
        fwrite($client, json_encode(['op' => 'advance', 'id' => 3, 'queue' => 'unknown', 'steps' => 5]) . "\n");
        $server->service();
        $third = json_decode(fgets($client), true);
        expect($third['id'])->toBe(3)
            ->and($third['ok'])->toBeTrue();

        fclose($client);
    } finally {
        $server->shutdown();
    }
});

it('triggers onChange after successful mutations only', function () {
    $socketPath = makeServerSocketPath();
    $rows = [];
    $global = ['credits' => 9000];

    $changes = 0;
    $server = new ParentStateServer(
        $socketPath,
        $rows,
        $global,
        onChange: function () use (&$changes): void {
            $changes++;
        },
    );
    $server->listen();

    try {
        $client = stream_socket_client('unix://' . $socketPath);
        stream_set_blocking($client, true);

        // set that actually changes → triggers onChange
        fwrite($client, json_encode(['op' => 'set', 'id' => 1, 'key' => 'credits', 'value' => 8000, 'queue' => null]) . "\n");
        $server->service();
        fgets($client);

        // set to same value → no change, no trigger
        fwrite($client, json_encode(['op' => 'set', 'id' => 2, 'key' => 'credits', 'value' => 8000, 'queue' => null]) . "\n");
        $server->service();
        fgets($client);

        // cas success → triggers onChange
        fwrite($client, json_encode(['op' => 'cas', 'id' => 3, 'key' => 'credits', 'old' => 8000, 'new' => 7500, 'queue' => null]) . "\n");
        $server->service();
        fgets($client);

        // cas failure → no trigger
        fwrite($client, json_encode(['op' => 'cas', 'id' => 4, 'key' => 'credits', 'old' => 9999, 'new' => 1, 'queue' => null]) . "\n");
        $server->service();
        fgets($client);

        // get → no trigger
        fwrite($client, json_encode(['op' => 'get', 'id' => 5, 'key' => 'credits', 'queue' => null]) . "\n");
        $server->service();
        fgets($client);

        fclose($client);

        expect($changes)->toBe(2);
    } finally {
        $server->shutdown();
    }
});
