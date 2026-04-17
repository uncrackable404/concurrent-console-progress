<?php

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Uncrackable404\ConcurrentConsoleProgress\ConcurrentProgress;
use Uncrackable404\ConcurrentConsoleProgress\ProgressState;

use function Uncrackable404\ConcurrentConsoleProgress\concurrent;

it('aggregates min via transform in synchronous mode', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    $observations = [9000, 9100, 8000, 8700, 7500];
    $tasks = [];
    foreach ($observations as $i => $value) {
        $tasks[] = ['queue' => 'api', 'steps' => 1, 'id' => $i + 1, 'observed' => $value];
    }

    concurrent(
        queues: [
            'api' => ['label' => 'api', 'total' => count($observations)],
        ],
        tasks: $tasks,
        concurrent: 0,
        process: function (array $task, ProgressState $state): void {
            $observed = (int) $task['observed'];
            $state->transform(
                'credits',
                fn (?int $current): int => $current === null ? $observed : min($current, $observed),
            );
            $state->advance('api', 1);
        },
        footer: [
            ['key' => 'credits', 'label' => 'CREDITS'],
        ],
    );

    $frame = $output->fetch();
    expect($frame)->toContain('7,500');

    ConcurrentProgress::setOutput(null);
});

it('runs the sync process closure in the same process with state', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    $parentPid = getmypid();
    $pidsSeen = [];

    concurrent(
        queues: [
            'api' => ['label' => 'api', 'total' => 1],
        ],
        tasks: [
            ['queue' => 'api', 'steps' => 1],
        ],
        concurrent: 0,
        process: function (array $task, ProgressState $state) use (&$pidsSeen): void {
            $pidsSeen[] = getmypid();
            $state->set('marker', 'sync');
            $state->advance('api', 1);
        },
        footer: [
            ['key' => 'marker', 'label' => 'MARKER'],
        ],
    );

    expect($pidsSeen)->each->toBe($parentPid);

    $frame = $output->fetch();
    expect($frame)->toContain('MARKER')->toContain('sync');

    ConcurrentProgress::setOutput(null);
});
