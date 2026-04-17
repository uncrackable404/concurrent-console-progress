<?php

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Uncrackable404\ConcurrentConsoleProgress\ConcurrentProgress;
use Uncrackable404\ConcurrentConsoleProgress\ProgressState;

use function Uncrackable404\ConcurrentConsoleProgress\concurrent;

it('aggregates min via transform across concurrent forked tasks', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    $observations = [9000, 9100, 8000, 8700, 7500, 8200];
    $tasks = [];
    foreach ($observations as $i => $value) {
        $tasks[] = ['queue' => 'api', 'steps' => 1, 'id' => $i + 1, 'observed' => $value];
    }

    $results = concurrent(
        queues: [
            'api' => ['label' => 'api', 'total' => count($observations)],
        ],
        tasks: $tasks,
        concurrent: 4,
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

    expect($results)->toHaveCount(count($observations));

    $frame = $output->fetch();

    expect($frame)
        ->toContain('CREDITS')
        ->toContain('7,500');

    $lastCreditsValue = null;
    if (preg_match_all('/CREDITS\s+(\d[\d,]*)/', $frame, $matches) === 1 || ! empty($matches[1])) {
        $lastCreditsValue = (int) str_replace(',', '', end($matches[1]));
    }

    expect($lastCreditsValue)->toBe(min($observations));

    ConcurrentProgress::setOutput(null);
});

it('supports set and get across forked tasks via RPC', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    concurrent(
        queues: [
            'api' => ['label' => 'api', 'total' => 2],
        ],
        tasks: [
            ['queue' => 'api', 'steps' => 1, 'id' => 1],
            ['queue' => 'api', 'steps' => 1, 'id' => 2],
        ],
        concurrent: 2,
        process: function (array $task, ProgressState $state): void {
            $state->set('last_id', (int) $task['id']);
            $state->advance('api', 1);
        },
        footer: [
            ['key' => 'last_id', 'label' => 'LAST ID'],
        ],
    );

    $frame = $output->fetch();
    expect($frame)->toContain('LAST ID');

    ConcurrentProgress::setOutput(null);
});
