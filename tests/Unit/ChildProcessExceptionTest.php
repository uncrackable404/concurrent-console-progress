<?php

use Uncrackable404\ConcurrentConsoleProgress\Exceptions\ChildProcessException;

it('creates an exception from a simple result array', function () {
    $result = [
        'queue' => 'cars',
        'error' => 'Something went wrong',
        'failed' => true,
    ];

    $exception = ChildProcessException::fromResult($result);

    expect($exception->getMessage())->toContain('cars')
        ->and($exception->getMessage())->toContain('Something went wrong')
        ->and($exception->snapshot()['queue'])->toBe('cars');
});

it('resolves error location outside vendor directory', function () {
    $exception = new Exception('Test');
    $task = ['queue' => 'test'];

    $snapshot = ChildProcessException::snapshotException($task, $exception);

    expect($snapshot['file'])->not->toContain('/vendor/')
        ->and($snapshot['line'])->toBeInt();
});

it('formats trace frames skipping vendor files during snapshot', function () {
    $exception = new Exception('Test');

    $snapshot = ChildProcessException::snapshotException(['queue' => 'cars'], $exception);

    foreach ($snapshot['trace'] as $line) {
        expect($line)->not->toContain('/vendor/');
    }
});

it('handles exceptions with empty traces gracefully', function () {
    $exception = new Exception('No trace');

    $snapshot = ChildProcessException::snapshotException(['queue' => 'cars'], $exception);
    expect($snapshot['trace'])->toHaveCount(1)
        ->and($snapshot['trace'][0])->toContain($exception->getFile() . ':' . $exception->getLine());
});

it('limits the number of trace lines in snapshot', function () {
    $generateDeepTrace = function ($depth, $callback) use (&$generateDeepTrace) {
        if ($depth <= 0) {
            return $callback();
        }

        return $generateDeepTrace($depth - 1, $callback);
    };

    $exception = $generateDeepTrace(10, function () {
        return new Exception('Deep');
    });

    $snapshot = ChildProcessException::snapshotException(['queue' => 'test'], $exception);

    // Default limit is 5.
    expect(count($snapshot['trace']))->toBe(5);
});

it('sanitizes terminal control sequences and console markup in formatted messages', function () {
    $exception = ChildProcessException::fromSnapshot([
        'queue' => 'imports',
        'error_context' => '<error>#42</error>' . "\e[31m",
        'class' => 'RuntimeException',
        'message' => 'Failed on <comment>secret</comment>' . "\e[2J",
        'file' => '/srv/app/secret/Worker.php',
        'line' => 88,
        'trace' => [
            '#0 /srv/app/secret/Worker.php:88 App\\Worker->run()',
        ],
    ]);

    expect($exception->getMessage())->toContain('imports');
    expect($exception->getMessage())->toContain('\<error\>#42\</error\>');
    expect($exception->getMessage())->toContain('\<comment\>secret\</comment\>');
    expect($exception->getMessage())->toContain('/srv/app/secret/Worker.php:88');
    expect($exception->getMessage())->toContain('Trace:');
    expect($exception->getMessage())->not->toContain("\e[31m");
    expect($exception->getMessage())->not->toContain("\e[2J");
});
