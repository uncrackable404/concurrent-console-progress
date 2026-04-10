<?php

use Uncrackable404\ConcurrentConsoleProgress\Support\FailFastFork;

it('throws a RuntimeException when failed with a string reason', function () {
    $fork = new FailFastFork();
    
    expect(fn() => $fork->fail('Something failed'))->toThrow(RuntimeException::class, 'Something failed');
});

it('throws the original exception when failed with a Throwable', function () {
    $fork = new FailFastFork();
    $exception = new InvalidArgumentException('Invalid');
    
    expect(fn() => $fork->fail($exception))->toThrow(InvalidArgumentException::class, 'Invalid');
});
