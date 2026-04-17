<?php

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Uncrackable404\ConcurrentConsoleProgress\ConcurrentProgress;

use function Uncrackable404\ConcurrentConsoleProgress\concurrent;

it('accepts single-argument closures in fork mode (state is optional)', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    $results = concurrent(
        queues: [
            'cars' => ['label' => 'cars', 'total' => 2],
        ],
        tasks: [
            ['queue' => 'cars', 'steps' => 1, 'id' => 1],
            ['queue' => 'cars', 'steps' => 1, 'id' => 2],
        ],
        concurrent: 2,
        process: fn (array $task): int => (int) $task['id'],
    );

    expect($results)->toHaveCount(2)
        ->and($results)->toContain(1)
        ->and($results)->toContain(2);

    ConcurrentProgress::setOutput(null);
});

it('accepts single-argument closures in sync mode (state is optional)', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    $results = concurrent(
        queues: [
            'cars' => ['label' => 'cars', 'total' => 2],
        ],
        tasks: [
            ['queue' => 'cars', 'steps' => 1, 'id' => 1],
            ['queue' => 'cars', 'steps' => 1, 'id' => 2],
        ],
        concurrent: 0,
        process: fn (array $task): int => (int) $task['id'],
    );

    expect($results)->toBe([1, 2]);

    ConcurrentProgress::setOutput(null);
});

it('accepts void closures — concurrent() returns array of nulls', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    $results = concurrent(
        queues: ['cars' => ['label' => 'cars', 'total' => 2]],
        tasks: [
            ['queue' => 'cars', 'steps' => 1, 'id' => 1],
            ['queue' => 'cars', 'steps' => 1, 'id' => 2],
        ],
        concurrent: 0,
        process: function (array $task): void {
            // no return
        },
    );

    expect($results)->toBe([null, null]);

    ConcurrentProgress::setOutput(null);
});
