<?php

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Uncrackable404\ConcurrentConsoleProgress\ConcurrentProgress;
use Uncrackable404\ConcurrentConsoleProgress\Exceptions\ChildProcessException;
use Uncrackable404\ConcurrentConsoleProgress\Output\ProgressTableRenderer;

use function Uncrackable404\ConcurrentConsoleProgress\concurrent;

it('runs concurrent progress through the helper entrypoint', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);

    $results = concurrent(
        queues: [],
        tasks: [],
        concurrent: 1,
        process: fn (array $task): array => $task,
    );

    expect($results)->toBe([]);

    ConcurrentProgress::setOutput(null);
});

it('redraws the full layout with Symfony cursor controls', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);
    $progress = new ConcurrentProgress;

    setPrivateProperty($progress, 'renderer', new ProgressTableRenderer(
        columns: [
            ['key' => 'label', 'label' => 'PROCESS'],
            ['key' => 'progress', 'label' => 'PROGRESS'],
            ['key' => 'percent', 'label' => '%', 'align' => 'right'],
            ['key' => 'processed', 'label' => 'PROCESSED', 'align' => 'right'],
            ['key' => 'tasks', 'label' => 'TASKS', 'align' => 'right'],
        ],
        footer: [],
    ));
    setPrivateProperty($progress, 'minSecondsBetweenRedraws', 0.0);

    $rows = [
        'cars' => [
            'label' => 'cars',
            'total' => 100,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 2,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
        'articles' => [
            'label' => 'articles',
            'total' => 100,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 2,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
    ];

    invokePrivateMethod($progress, 'startLayout', $rows, []);

    $initial = $output->fetch();

    expect($initial)
        ->toContain('PROCESS')
        ->toContain('cars')
        ->toContain('articles')
        ->toContain('TOTAL')
        ->toContain('░');

    $rows['cars']['processed'] = 50;
    $rows['cars']['tasks_completed'] = 1;

    invokePrivateMethod($progress, 'updateLayout', $rows, [], false);

    $delta = $output->fetch();

    expect($delta)
        ->toContain("\x1b[")
        ->toContain("\x1b[0J")
        ->toContain('50 / 100')
        ->toContain('1 / 2')
        ->toContain('TOTAL')
        ->toContain('▓');

    ConcurrentProgress::setOutput(null);
});

it('throttles intermediate redraws but always renders the final frame', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);
    $progress = new ConcurrentProgress;
    setPrivateProperty($progress, 'renderer', new ProgressTableRenderer(
        columns: [
            ['key' => 'label', 'label' => 'PROCESS'],
            ['key' => 'progress', 'label' => 'PROGRESS'],
            ['key' => 'percent', 'label' => '%', 'align' => 'right'],
            ['key' => 'processed', 'label' => 'PROCESSED', 'align' => 'right'],
            ['key' => 'tasks', 'label' => 'TASKS', 'align' => 'right'],
        ],
        footer: [],
    ));
    setPrivateProperty($progress, 'minSecondsBetweenRedraws', 60.0);

    $rows = [
        'cars' => [
            'label' => 'cars',
            'total' => 100,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 2,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
    ];

    invokePrivateMethod($progress, 'startLayout', $rows, []);
    $output->fetch();

    $rows['cars']['processed'] = 50;
    $rows['cars']['tasks_completed'] = 1;

    invokePrivateMethod($progress, 'updateLayout', $rows, [], false);

    expect($output->fetch())->toBe('');

    $rows['cars']['processed'] = 100;
    $rows['cars']['tasks_completed'] = 2;
    $rows['cars']['completed'] = true;

    invokePrivateMethod($progress, 'updateLayout', $rows, [], true);

    expect($output->fetch())
        ->toContain('100 / 100')
        ->toContain('2 / 2');

    ConcurrentProgress::setOutput(null);
});

it('performs a full redraw when the table layout changes', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);
    $progress = new ConcurrentProgress;
    setPrivateProperty($progress, 'renderer', new ProgressTableRenderer(
        columns: [
            ['key' => 'label', 'label' => 'PROCESS'],
            ['key' => 'progress', 'label' => 'PROGRESS'],
            ['key' => 'percent', 'label' => '%', 'align' => 'right'],
            ['key' => 'processed', 'label' => 'PROCESSED', 'align' => 'right'],
            ['key' => 'tasks', 'label' => 'TASKS', 'align' => 'right'],
        ],
        footer: [],
    ));
    setPrivateProperty($progress, 'minSecondsBetweenRedraws', 0.0);

    $rows = [
        'cars' => [
            'label' => 'cars',
            'total' => 100,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 2,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
    ];

    invokePrivateMethod($progress, 'startLayout', $rows, []);
    $output->fetch();

    $rows['cars']['label'] = 'case-histories';
    $rows['cars']['processed'] = 100;
    $rows['cars']['tasks_completed'] = 2;
    $rows['cars']['completed'] = true;

    invokePrivateMethod($progress, 'updateLayout', $rows, [], true);

    $delta = $output->fetch();

    expect($delta)
        ->toContain("\x1b[0J")
        ->toContain('case-histories')
        ->toContain('TOTAL');

    ConcurrentProgress::setOutput(null);
});

it('renders the failed row in red before bubbling the failure', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);
    $progress = new ConcurrentProgress;
    setPrivateProperty($progress, 'renderer', new ProgressTableRenderer(
        columns: [
            ['key' => 'label', 'label' => 'PROCESS'],
            ['key' => 'progress', 'label' => 'PROGRESS'],
            ['key' => 'percent', 'label' => '%', 'align' => 'right'],
            ['key' => 'processed', 'label' => 'PROCESSED', 'align' => 'right'],
            ['key' => 'tasks', 'label' => 'TASKS', 'align' => 'right'],
        ],
        footer: [],
    ));
    setPrivateProperty($progress, 'minSecondsBetweenRedraws', 60.0);

    $rows = [
        'cars' => [
            'label' => 'cars',
            'total' => 100,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 2,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
    ];

    invokePrivateMethod($progress, 'startLayout', $rows, []);
    $output->fetch();

    $failureReason = invokePrivateMethod($progress, 'applyResultAndRender', $rows, [], [
        'queue' => 'cars',
        'steps' => 50,
        'advance' => 0,
        'meta' => [],
        'global' => [],
        'failed' => true,
        'exception' => [
            'queue' => 'cars',
            'error_context' => 'offset 22500',
            'class' => RuntimeException::class,
            'message' => 'Something went wrong',
            'file' => '/tmp/failure.php',
            'line' => 42,
            'trace' => [
                '#0 /tmp/failure.php:42 ImportDatabase::handle',
            ],
        ],
    ]);

    $delta = $output->fetch();

    expect($failureReason)->toBeInstanceOf(ChildProcessException::class);
    expect($failureReason->getMessage())
        ->toContain('cars offset 22500')
        ->toContain(RuntimeException::class)
        ->toContain('at /tmp/failure.php:42');
    expect($delta)
        ->not->toBe('')
        ->toContain("\x1b[31m")
        ->toContain('cars')
        ->toContain('1 / 2');

    ConcurrentProgress::setOutput(null);
});

it('creates a serializable exception snapshot from a trace containing closures', function () {
    $progress = new ConcurrentProgress(new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true));
    $exception = exceptionWithCallbackTrace();

    $snapshot = ChildProcessException::snapshotException([
        'queue' => 'cars',
        'error_context' => 'offset 22500',
    ], $exception);

    $serializedSnapshot = serialize($snapshot);

    $pestFile = realpath(__DIR__ . '/../Pest.php');
    expect($serializedSnapshot)->toBeString();
    expect($snapshot['class'])->toBe(RuntimeException::class);
    expect($snapshot['message'])->toBe('Request failed');
    expect($snapshot['file'])->toBe($pestFile);
    expect($snapshot['line'])->toBeInt();
    expect(implode(PHP_EOL, $snapshot['trace']))
        ->toContain($pestFile)
        ->not->toContain('Closure::__set_state');
});

it('truncates long child process error messages while preserving the original location', function () {
    $exception = ChildProcessException::fromSnapshot([
        'queue' => 'cars',
        'error_context' => 'offset 22500',
        'class' => 'Illuminate\Http\Client\RequestException',
        'message' => str_repeat('Gateway timeout ', 40),
        'file' => '/tmp/ImportDatabase.php',
        'line' => 136,
        'trace' => [
            '#0 /tmp/ImportDatabase.php:136 ImportDatabase::processBatch',
            '#1 /tmp/ImportDatabase.php:92 ImportDatabase::handle',
        ],
    ]);

    expect($exception->getMessage())
        ->toContain('cars offset 22500')
        ->toContain('Illuminate\Http\Client\RequestException')
        ->toContain('at /tmp/ImportDatabase.php:136')
        ->toContain('Trace:')
        ->toContain('... (truncated)');
});

it('uses a fixed progress bar width of 20 characters', function () {
    $renderer = new ProgressTableRenderer(
        columns: [
            ['key' => 'label', 'label' => 'PROCESS'],
            ['key' => 'progress', 'label' => 'PROGRESS'],
            ['key' => 'percent', 'label' => '%', 'align' => 'right'],
            ['key' => 'processed', 'label' => 'PROCESSED', 'align' => 'right'],
            ['key' => 'tasks', 'label' => 'TASKS', 'align' => 'right'],
        ],
        footer: [],
    );

    $initialFrame = $renderer->render([
        'cars' => [
            'label' => 'cars',
            'total' => 1000,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 10,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
    ], [], [
        'processed' => 0,
        'total' => 1000,
        'elapsed' => 0,
        'eta' => null,
        'completed' => false,
    ]);

    $updatedFrame = $renderer->render([
        'cars' => [
            'label' => 'cars',
            'total' => 1000,
            'processed' => 1000,
            'tasks_completed' => 10,
            'tasks_total' => 10,
            'meta' => [],
            'failed' => false,
            'completed' => true,
        ],
    ], [], [
        'processed' => 1000,
        'total' => 1000,
        'elapsed' => 1,
        'eta' => 0,
        'completed' => true,
    ]);

    preg_match('/\[(?<progress>[^\]]+)\]/u', $initialFrame, $initialMatch);
    preg_match('/\[(?<progress>[^\]]+)\]/u', $updatedFrame, $updatedMatch);

    expect($initialMatch['progress'] ?? null)->not->toBeNull();
    expect($updatedMatch['progress'] ?? null)->not->toBeNull();
    expect(mb_strwidth($initialMatch['progress']))->toBe(20);
    expect(mb_strwidth($updatedMatch['progress']))->toBe(20);
    expect($initialMatch['progress'])->toContain('░');
    expect($updatedMatch['progress'])->toContain('▓');
});

it('renders neutral rows in gray', function () {
    $renderer = new ProgressTableRenderer(
        columns: [
            ['key' => 'label', 'label' => 'PROCESS'],
            ['key' => 'progress', 'label' => 'PROGRESS'],
            ['key' => 'percent', 'label' => '%', 'align' => 'right'],
            ['key' => 'processed', 'label' => 'PROCESSED', 'align' => 'right'],
            ['key' => 'tasks', 'label' => 'TASKS', 'align' => 'right'],
        ],
        footer: [],
    );

    $frame = $renderer->render([
        'cars' => [
            'label' => 'cars',
            'total' => 100,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 2,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
    ], [], [
        'processed' => 0,
        'total' => 100,
        'elapsed' => 0,
        'eta' => null,
        'completed' => false,
    ]);

    expect($frame)
        ->toContain('·  cars')
        ->toContain('<fg=gray>');
});

it('omits the processed default column when it duplicates tasks', function () {
    $progress = new ConcurrentProgress(new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true));

    $columns = invokePrivateMethod($progress, 'normalizeColumns', [], [
        'cars' => ['label' => 'cars', 'total' => 2],
        'articles' => ['label' => 'articles', 'total' => 1],
    ], [
        ['queue' => 'cars', 'steps' => 1],
        ['queue' => 'cars', 'steps' => 1],
        ['queue' => 'articles', 'steps' => 1],
    ]);

    expect(array_column($columns, 'key'))
        ->toBe(['label', 'progress', 'percent', 'tasks']);
});

it('keeps the processed default column when it differs from tasks', function () {
    $progress = new ConcurrentProgress(new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true));

    $columns = invokePrivateMethod($progress, 'normalizeColumns', [], [
        'cars' => ['label' => 'cars', 'total' => 500],
    ], [
        ['queue' => 'cars', 'steps' => 500],
    ]);

    expect(array_column($columns, 'key'))
        ->toBe(['label', 'progress', 'percent', 'processed', 'tasks']);
});

it('merges custom columns into the default columns', function () {
    $progress = new ConcurrentProgress(new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true));

    $columns = invokePrivateMethod($progress, 'normalizeColumns', [
        ['key' => 'files', 'label' => 'WITH FILES', 'align' => 'right'],
        ['key' => 'processed', 'label' => 'DONE', 'align' => 'right'],
    ], [
        'cars' => ['label' => 'cars', 'total' => 500],
    ], [
        ['queue' => 'cars', 'steps' => 500],
    ]);

    expect(array_column($columns, 'key'))
        ->toBe(['label', 'progress', 'percent', 'processed', 'tasks', 'files']);
    expect($columns[3]['label'])->toBe('DONE');
    expect($columns[5]['label'])->toBe('WITH FILES');
});

it('merges custom footer items into the default footer', function () {
    $progress = new ConcurrentProgress(new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true));

    $footer = invokePrivateMethod($progress, 'normalizeFooter', [
        ['key' => 'rl', 'label' => 'CREDITS'],
        ['key' => 'eta', 'label' => 'REMAINING'],
    ]);

    expect(array_column($footer, 'key'))
        ->toBe(['elapsed', 'eta', 'rl']);
    expect($footer[1]['label'])->toBe('REMAINING');
    expect($footer[2]['label'])->toBe('CREDITS');
});

it('keeps expanded columns stable across subsequent frames', function () {
    $renderer = new ProgressTableRenderer(
        columns: [
            ['key' => 'label', 'label' => 'PROCESS'],
            ['key' => 'progress', 'label' => 'PROGRESS'],
            ['key' => 'percent', 'label' => '%', 'align' => 'right'],
            ['key' => 'processed', 'label' => 'PROCESSED', 'align' => 'right'],
            ['key' => 'tasks', 'label' => 'TASKS', 'align' => 'right'],
        ],
        footer: [],
    );

    $initialFrame = $renderer->render([
        'cars' => [
            'label' => 'cars',
            'total' => 100,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 2,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
    ], [], [
        'processed' => 0,
        'total' => 100,
        'elapsed' => 0,
        'eta' => null,
        'completed' => false,
    ]);

    $expandedFrame = $renderer->render([
        'cars' => [
            'label' => 'case-histories',
            'total' => 100,
            'processed' => 100,
            'tasks_completed' => 2,
            'tasks_total' => 2,
            'meta' => [],
            'failed' => false,
            'completed' => true,
        ],
    ], [], [
        'processed' => 100,
        'total' => 100,
        'elapsed' => 1,
        'eta' => 0,
        'completed' => true,
    ]);

    $stableFrame = $renderer->render([
        'cars' => [
            'label' => 'cars',
            'total' => 100,
            'processed' => 50,
            'tasks_completed' => 1,
            'tasks_total' => 2,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ],
    ], [], [
        'processed' => 50,
        'total' => 100,
        'elapsed' => 1,
        'eta' => 1,
        'completed' => false,
    ]);

    expect(progressColumnOffset($expandedFrame, 'case-histories'))
        ->toBeGreaterThan(progressColumnOffset($initialFrame, 'cars'));
    expect(progressColumnOffset($stableFrame, 'cars'))
        ->toBe(progressColumnOffset($expandedFrame, 'case-histories'));
});

it('keeps sticky widths within the terminal budget after multiple expansions', function () {
    $renderer = new ProgressTableRenderer(
        columns: [
            ['key' => 'label', 'label' => 'PROCESS'],
            ['key' => 'progress', 'label' => 'PROGRESS'],
            ['key' => 'percent', 'label' => '%', 'align' => 'right'],
            ['key' => 'processed', 'label' => 'PROCESSED', 'align' => 'right'],
            ['key' => 'tasks', 'label' => 'TASKS', 'align' => 'right'],
            ['key' => 'updated', 'label' => 'UPDATED', 'align' => 'right'],
            ['key' => 'files', 'label' => 'FILES', 'align' => 'right'],
        ],
        footer: [
            ['key' => 'elapsed', 'label' => 'ELAPSED'],
            ['key' => 'eta', 'label' => 'ETA'],
            ['key' => 'credits', 'label' => 'CREDITS'],
        ],
    );

    $renderer->render([
        'cars' => [
            'label' => 'case-histories',
            'total' => 102,
            'processed' => 102,
            'tasks_completed' => 1,
            'tasks_total' => 1,
            'meta' => ['updated' => 0, 'files' => 24],
            'failed' => false,
            'completed' => true,
        ],
    ], ['credits' => 9784], [
        'processed' => 102,
        'total' => 102,
        'elapsed' => 1,
        'eta' => 0,
        'completed' => true,
    ]);

    $frame = $renderer->render([
        'cars' => [
            'label' => 'connections',
            'total' => 115892,
            'processed' => 2000,
            'tasks_completed' => 4,
            'tasks_total' => 232,
            'meta' => ['updated' => 0, 'files' => 1061],
            'failed' => false,
            'completed' => false,
        ],
        'articles' => [
            'label' => 'race-numbers',
            'total' => 1312,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 3,
            'meta' => ['updated' => null, 'files' => null],
            'failed' => false,
            'completed' => false,
        ],
    ], ['credits' => 9784], [
        'processed' => 2000,
        'total' => 117204,
        'elapsed' => 21,
        'eta' => 480,
        'completed' => false,
    ]);

    $availableWidth = max((new Terminal)->getWidth() - 1, 20);

    foreach (cleanConsoleLines($frame) as $line) {
        expect(mb_strwidth($line))->toBeLessThanOrEqual($availableWidth);
    }
});

it('clips queue rows to the available terminal height', function () {
    $renderer = new ProgressTableRenderer(
        columns: [
            ['key' => 'label', 'label' => 'PROCESS'],
            ['key' => 'progress', 'label' => 'PROGRESS'],
            ['key' => 'percent', 'label' => '%', 'align' => 'right'],
            ['key' => 'processed', 'label' => 'PROCESSED', 'align' => 'right'],
            ['key' => 'tasks', 'label' => 'TASKS', 'align' => 'right'],
        ],
        footer: [
            ['key' => 'elapsed', 'label' => 'ELAPSED'],
            ['key' => 'eta', 'label' => 'ETA'],
        ],
    );

    $rows = [];

    foreach (range(1, 6) as $index) {
        $rows['queue-' . $index] = [
            'label' => 'queue-' . $index,
            'total' => 100,
            'processed' => $index === 6 ? 50 : 0,
            'tasks_completed' => $index === 6 ? 1 : 0,
            'tasks_total' => 2,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ];
    }

    $tableRows = invokePrivateMethod(
        $renderer,
        'tableRows',
        $rows,
        [],
        [
            'processed' => 50,
            'total' => 600,
            'elapsed' => 10,
            'eta' => 100,
            'completed' => false,
        ],
        20,
        6,
    );

    expect($tableRows)->toHaveCount(5)
        ->and(implode(' ', $tableRows[0]['cells']))->toContain('queue-1')
        ->and(implode(' ', $tableRows[1]['cells']))->toContain('queue-6')
        ->and(implode(' ', $tableRows[2]['cells']))->toContain('TOTAL')
        ->and(implode(' ', $tableRows[3]['cells']))->toContain('ELAPSED')
        ->and(implode(' ', $tableRows[4]['cells']))->toContain('ETA');
});

it('wraps and runs a task directly', function () {
    $progress = new ConcurrentProgress;
    $task = ['queue' => 'cars', 'steps' => 10, 'error_context' => 'batch-1'];

    $wrapper = invokePrivateMethod($progress, 'wrapTask', $task, function ($task) {
        return ['advance' => 5, 'meta' => ['status' => 'halfway'], 'global' => ['overall' => 'starting']];
    });

    $result = $wrapper();

    expect($result)->toBe([
        'queue' => 'cars',
        'steps' => 10,
        'advance' => 5,
        'meta' => ['status' => 'halfway'],
        'global' => ['overall' => 'starting'],
        'error_context' => 'batch-1',
        'failed' => false,
    ]);

    $wrapperFailing = invokePrivateMethod($progress, 'wrapTask', $task, function ($task) {
        throw new RuntimeException('Processing failed');
    });

    $resultFailed = $wrapperFailing();

    expect($resultFailed)->toBeArray()
        ->and($resultFailed['failed'])->toBeTrue()
        ->and($resultFailed['exception'])->toBeArray()
        ->and($resultFailed['exception']['message'])->toBe('Processing failed');
});

it('identifies redundant processed column correctly', function () {
    $progress = new ConcurrentProgress;

    $queues = ['cars' => ['label' => 'Cars', 'total' => 2]];
    $tasks = [
        ['queue' => 'cars', 'steps' => 1],
        ['queue' => 'cars', 'steps' => 1],
    ];

    expect(invokePrivateMethod($progress, 'processedColumnIsRedundant', $queues, $tasks))->toBeTrue();

    $tasksMismatch = [
        ['queue' => 'cars', 'steps' => 2],
    ];
    // steps=2, taskCount=1. 2 != 1, returns false.
    expect(invokePrivateMethod($progress, 'processedColumnIsRedundant', $queues, $tasksMismatch))->toBeFalse();

    $queuesMismatch = ['cars' => ['label' => 'Cars', 'total' => 3]];
    $tasksCorrect = [
        ['queue' => 'cars', 'steps' => 1],
        ['queue' => 'cars', 'steps' => 1],
        ['queue' => 'cars', 'steps' => 1],
    ];
    // This is True.
    expect(invokePrivateMethod($progress, 'processedColumnIsRedundant', $queuesMismatch, $tasksCorrect))->toBeTrue();

    $queuesFinalMismatch = ['cars' => ['label' => 'Cars', 'total' => 4]];
    // total=4, taskCount=3. returns false.
    expect(invokePrivateMethod($progress, 'processedColumnIsRedundant', $queuesFinalMismatch, $tasksCorrect))->toBeFalse();
});

it('merges definitions while skipping invalid custom entries', function () {
    $progress = new ConcurrentProgress;
    $defaults = [['key' => 'label', 'label' => 'LABEL']];
    $custom = [['label' => 'INVALID'], ['key' => 'label', 'label' => 'VALID'], ['key' => 'extra', 'label' => 'EXTRA']];

    $merged = invokePrivateMethod($progress, 'mergeDefinitions', $defaults, $custom);

    expect($merged)->toHaveCount(2)
        ->and($merged[0]['label'])->toBe('VALID')
        ->and($merged[1]['key'])->toBe('extra');
});

it('skips default definitions without keys during merge', function () {
    $progress = new ConcurrentProgress;
    $defaults = [['key' => 'label'], ['label' => 'NO_KEY']];
    $custom = [['key' => 'extra']]; // Non-empty to bypass early return
    $merged = invokePrivateMethod($progress, 'mergeDefinitions', $defaults, $custom);
    expect($merged)->toHaveCount(2); // 'label' from defaults, 'extra' from custom. 'NO_KEY' skipped.
});

it('creates rows for undeclared queues and increments totals', function () {
    $progress = new ConcurrentProgress;
    $queues = ['declared' => ['label' => 'Declared', 'total' => 10]];
    $tasks = [
        ['queue' => 'declared', 'steps' => 5],
        ['queue' => 'undeclared', 'steps' => 5],
        ['queue' => 'undeclared', 'steps' => 3],
    ];

    $rows = invokePrivateMethod($progress, 'makeRows', $queues, $tasks);

    expect($rows)->toHaveCount(2)
        ->and($rows['declared']['total'])->toBe(10)
        ->and($rows['undeclared']['total'])->toBe(8)
        ->and($rows['undeclared']['tasks_total'])->toBe(2);
});

it('handles non-decorated output gracefully', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false); // isDecorated = false
    ConcurrentProgress::setOutput($output);
    $progress = new ConcurrentProgress;

    invokePrivateMethod($progress, 'startLayout', [], []);
    expect(getPrivateProperty($progress, 'cursor'))->toBeNull();

    invokePrivateMethod($progress, 'updateLayout', [], [], false);
    expect($output->fetch())->toBe('');

    ConcurrentProgress::setOutput(null);
});

it('skips layout overwrite when lines are identical or cursor is missing', function () {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    ConcurrentProgress::setOutput($output);
    $progress = new ConcurrentProgress;
    setPrivateProperty($progress, 'renderer', new ProgressTableRenderer([], []));

    invokePrivateMethod($progress, 'startLayout', [], []);
    $output->fetch(); // Clear buffer

    setPrivateProperty($progress, 'lastFrame', ['line 1', 'line 2']);
    invokePrivateMethod($progress, 'overwriteLayout', ['line 1', 'line 2']);

    expect($output->fetch())->toBe('');

    setPrivateProperty($progress, 'cursor', null);
    invokePrivateMethod($progress, 'overwriteLayout', ['new line']);
    expect($output->fetch())->toBe('');

    ConcurrentProgress::setOutput(null);
});

it('skips redundant redraws based on time and content', function () {
    $progress = new ConcurrentProgress;
    setPrivateProperty($progress, 'lastFrame', ['same']);
    setPrivateProperty($progress, 'lastRenderedAt', microtime(true));
    setPrivateProperty($progress, 'minSecondsBetweenRedraws', 10.0);

    expect(invokePrivateMethod($progress, 'shouldSkipRedraw', ['same']))->toBeTrue();
    expect(invokePrivateMethod($progress, 'shouldSkipRedraw', ['different']))->toBeTrue();

    setPrivateProperty($progress, 'lastRenderedAt', microtime(true) - 20.0);
    expect(invokePrivateMethod($progress, 'shouldSkipRedraw', ['different']))->toBeFalse();
});

it('estimates ETA correctly', function () {
    $progress = new ConcurrentProgress;

    // Completed or empty
    expect(invokePrivateMethod($progress, 'estimateEta', 100, 100, 10, true))->toBe(0);
    expect(invokePrivateMethod($progress, 'estimateEta', 0, 0, 10))->toBe(0);

    // Initial state
    expect(invokePrivateMethod($progress, 'estimateEta', 0, 100, 0))->toBeNull();

    // Normal case: 50 items in 10s = 5 items/s. Total 100. Remaining 50. ETA = 10s.
    expect(invokePrivateMethod($progress, 'estimateEta', 50, 100, 10))->toBe(10);

    // Negative items per second (should not happen but for coverage)
    expect(invokePrivateMethod($progress, 'estimateEta', -1, 100, 10))->toBeNull();
});

it('merges row meta with non-numeric values and ignores unknown queues', function () {
    $progress = new ConcurrentProgress;
    $rows = ['cars' => ['meta' => ['speed' => 10, 'status' => 'waiting']]];

    // Valid queue, non-numeric value
    $meta = $rows['cars']['meta'];
    $incoming = ['speed' => 5, 'status' => 'driving'];
    (function ($incoming) use (&$meta) {
        $this->mergeRowMeta($meta, $incoming);
    })->call($progress, $incoming);

    expect($meta['speed'])->toBe(15)
        ->and($meta['status'])->toBe('driving');

    // Unknown queue check in applyResult
    $global = [];
    invokePrivateMethod($progress, 'applyResult', $rows, $global, ['queue' => 'unknown']);
    expect($rows)->not->toHaveKey('unknown');
});
