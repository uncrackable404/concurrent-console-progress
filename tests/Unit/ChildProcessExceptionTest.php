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
    $exception = new \Exception('Test');
    $task = ['queue' => 'test'];
    
    $snapshot = ChildProcessException::snapshotException($task, $exception);
    
    expect($snapshot['file'])->not->toContain('/vendor/')
        ->and($snapshot['line'])->toBeInt();
});

it('formats trace frames skipping vendor files during snapshot', function () {
    $exception = new \Exception('Test');
    
    $snapshot = ChildProcessException::snapshotException(['queue' => 'cars'], $exception);
    
    foreach ($snapshot['trace'] as $line) {
        expect($line)->not->toContain('/vendor/');
    }
});

it('handles exceptions with empty traces gracefully', function () {
    $exception = new \Exception('No trace');
    
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
    
    $exception = $generateDeepTrace(10, function() {
        return new \Exception('Deep');
    });
    
    $snapshot = ChildProcessException::snapshotException(['queue' => 'test'], $exception);
    
    // Default limit is 5.
    expect(count($snapshot['trace']))->toBe(5);
});
