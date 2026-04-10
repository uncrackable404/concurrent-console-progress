<?php

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Uncrackable404\ConcurrentConsoleProgress\ConcurrentProgress;
use Uncrackable404\ConcurrentConsoleProgress\Exceptions\ChildProcessException;
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
        concurrency: 1,
        process: function (array $task): array {
            return [
                'advance' => 1,
                'meta' => ['processed_id' => $task['id']],
                'global' => ['last_id' => $task['id']],
            ];
        },
    );

    expect($results)->toHaveCount(2)
        ->and($results[0]['meta']['processed_id'])->toBe(1)
        ->and($results[1]['meta']['processed_id'])->toBe(2);
    
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
            concurrency: 1,
            process: function (array $task): array {
                if ($task['id'] === 1) {
                    throw new RuntimeException('Test failure');
                }
                return ['advance' => 1];
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
