<?php

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Uncrackable404\ConcurrentConsoleProgress\ConcurrentProgress;
use Uncrackable404\ConcurrentConsoleProgress\Exceptions\ChildProcessException;
use Uncrackable404\ConcurrentConsoleProgress\ProgressState;

use function Uncrackable404\ConcurrentConsoleProgress\concurrent;

it('completes tasks successfully with real forking', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    $results = concurrent(
        queues: [
            'cars' => ['label' => 'Cars', 'total' => 2],
        ],
        tasks: [
            ['queue' => 'cars', 'steps' => 1, 'id' => 1],
            ['queue' => 'cars', 'steps' => 1, 'id' => 2],
        ],
        concurrent: 1,
        process: function (array $task, ProgressState $state): array {
            $state->advance('cars', 1);
            $state->set('last_id', (int) $task['id']);

            return ['processed_id' => (int) $task['id']];
        },
    );

    expect($results)->toHaveCount(2)
        ->and($results[0])->toBe(['processed_id' => 1])
        ->and($results[1])->toBe(['processed_id' => 2]);

    $frame = $output->fetch();
    expect($frame)->toContain('Cars')
        ->toContain('2 / 2')
        ->toContain('100%');

    ConcurrentProgress::setOutput(null);
});

it('fails and stops early when a task throws an exception', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    try {
        concurrent(
            queues: [
                'cars' => ['label' => 'Cars', 'total' => 2],
            ],
            tasks: [
                ['queue' => 'cars', 'steps' => 1, 'id' => 1],
                ['queue' => 'cars', 'steps' => 1, 'id' => 2],
            ],
            concurrent: 1,
            process: function (array $task, ProgressState $state): void {
                if ($task['id'] === 1) {
                    throw new RuntimeException('Test failure');
                }

                $state->advance('cars', 1);
            },
        );
        $this->fail('Exception not thrown');
    } catch (ChildProcessException $exception) {
        expect($exception->getMessage())->toContain('Test failure');
    }

    $frame = $output->fetch();
    expect($frame)->toContain("\x1b[31m"); // Red error row

    ConcurrentProgress::setOutput(null);
});

it('runs tasks synchronously in the same process with concurrency 0', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    $parentPid = getmypid();
    $pidsSeen = [];

    $results = concurrent(
        queues: [
            'cars' => ['label' => 'Cars', 'total' => 2],
        ],
        tasks: [
            ['queue' => 'cars', 'steps' => 1, 'id' => 1],
            ['queue' => 'cars', 'steps' => 1, 'id' => 2],
        ],
        concurrent: 0,
        process: function (array $task, ProgressState $state) use (&$pidsSeen): array {
            $pidsSeen[] = getmypid();
            $state->advance('cars', 1);
            $state->set('last_id', (int) $task['id']);

            return ['processed_id' => (int) $task['id']];
        },
    );

    expect($results)->toHaveCount(2)
        ->and($results[0])->toBe(['processed_id' => 1])
        ->and($results[1])->toBe(['processed_id' => 2])
        ->and($pidsSeen)->each->toBe($parentPid);

    $frame = $output->fetch();
    expect($frame)->toContain('Cars')
        ->toContain('2 / 2')
        ->toContain('100%');

    ConcurrentProgress::setOutput(null);
});

it('fails fast synchronously when a task throws with concurrency 0', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    try {
        concurrent(
            queues: [
                'cars' => ['label' => 'Cars', 'total' => 3],
            ],
            tasks: [
                ['queue' => 'cars', 'steps' => 1, 'id' => 1],
                ['queue' => 'cars', 'steps' => 1, 'id' => 2],
                ['queue' => 'cars', 'steps' => 1, 'id' => 3],
            ],
            concurrent: 0,
            process: function (array $task, ProgressState $state): void {
                if ($task['id'] === 2) {
                    throw new RuntimeException('Sync failure');
                }

                $state->advance('cars', 1);
            },
        );
        $this->fail('Exception not thrown');
    } catch (ChildProcessException $exception) {
        expect($exception->getMessage())->toContain('Sync failure');
    }

    $frame = $output->fetch();
    expect($frame)->toContain("\x1b[31m");

    ConcurrentProgress::setOutput(null);
});
