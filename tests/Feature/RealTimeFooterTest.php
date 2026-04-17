<?php

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Uncrackable404\ConcurrentConsoleProgress\ConcurrentProgress;
use Uncrackable404\ConcurrentConsoleProgress\ProgressState;

use function Uncrackable404\ConcurrentConsoleProgress\concurrent;

final class RecordingOutput extends BufferedOutput
{
    /** @var array<int, array{time: float, message: string}> */
    public array $frames = [];

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite($message, $newline);

        $this->frames[] = [
            'time' => microtime(true),
            'message' => $message,
        ];
    }
}

it('renders credits value before the task completes', function () {
    $output = new RecordingOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    $start = microtime(true);

    concurrent(
        queues: [
            'api' => ['label' => 'api', 'total' => 1],
        ],
        tasks: [
            ['queue' => 'api', 'steps' => 1],
        ],
        concurrent: 1,
        process: function (array $task, ProgressState $state): void {
            $state->set('credits', 9000);
            usleep(1_500_000);
            $state->advance('api', 1);
        },
        footer: [
            ['key' => 'credits', 'label' => 'CREDITS'],
        ],
    );

    $taskDuration = microtime(true) - $start;

    expect($taskDuration)->toBeGreaterThan(1.4);

    $earlyRenderTime = null;
    foreach ($output->frames as $frame) {
        if (str_contains($frame['message'], '9,000')) {
            $earlyRenderTime = $frame['time'] - $start;
            break;
        }
    }

    expect($earlyRenderTime)->not->toBeNull();
    expect($earlyRenderTime)->toBeLessThan(1.0);

    ConcurrentProgress::setOutput(null);
});
