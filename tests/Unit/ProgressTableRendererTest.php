<?php

use Uncrackable404\ConcurrentConsoleProgress\Output\ProgressTableRenderer;
use Symfony\Component\Console\Terminal;

it('limits the number of visible rows based on terminal height', function () {
    $renderer = new ProgressTableRenderer(
        columns: [['key' => 'label', 'label' => 'LABEL']],
        footer: [],
        terminal: new class extends Terminal {
            public function getHeight(): int { return 10; }
            public function getWidth(): int { return 80; }
        }
    );
    
    // reserved lines = 1 (header) + 1 (total) + 0 (footer) = 2.
    // available = 10 - 1 = 9.
    // maxQueueRows = 9 - 2 = 7.
    
    $rows = [];
    for ($i = 0; $i < 20; $i++) {
        $rows["row-$i"] = [
            'label' => "Row $i",
            'total' => 10,
            'processed' => 0,
            'tasks_completed' => 0,
            'tasks_total' => 1,
            'meta' => [],
            'failed' => false,
            'completed' => false,
        ];
    }
    
    // Make 10 rows active so it hits the limit (7) and breaks in the first loop.
    for ($i = 0; $i < 10; $i++) {
        $rows["row-$i"]['processed'] = 1;
    }
    
    $frame = $renderer->render($rows, [], ['processed' => 10, 'total' => 200]);
    
    $lines = explode(PHP_EOL, trim($frame));
    // It should have hit the break (at 7).
    expect(count($lines))->toBeLessThanOrEqual(10);
    expect($frame)->toContain('Row 0')
        ->and($frame)->toContain('Row 6')
        ->and($frame)->not->toContain('Row 7');

    // To hit the 'continue' in the second loop, we need active rows < maxQueueRows.
    // Let's reset.
    $rows2 = [];
    for ($i = 0; $i < 20; $i++) {
        $rows2["row-$i"] = [
            'label' => "Row $i",
            'total' => 10, 'processed' => 0, 'tasks_completed' => 0, 'tasks_total' => 1,
            'meta' => [], 'failed' => false, 'completed' => false,
        ];
    }
    // 3 active rows. maxQueueRows = 7.
    $rows2["row-0"]['processed'] = 1;
    $rows2["row-1"]['processed'] = 1;
    $rows2["row-2"]['processed'] = 1;
    
    // First loop selects 0, 1, 2.
    // Second loop starts from 0, sees 0, 1, 2 already selected -> hits CONTINUE.
    $frame2 = $renderer->render($rows2, [], ['processed' => 3, 'total' => 200]);
    expect($frame2)->toContain('Row 0')
        ->and($frame2)->toContain('Row 6');
});

it('merges non-numeric meta values in summary row', function () {
    $renderer = new ProgressTableRenderer(
        columns: [['key' => 'status', 'label' => 'STATUS']],
        footer: []
    );
    
    $rows = [
        ['label' => 'A', 'meta' => ['status' => 'OK'], 'processed' => 1, 'total' => 1, 'tasks_completed' => 1, 'tasks_total' => 1],
        ['label' => 'B', 'meta' => ['status' => 'FAIL'], 'processed' => 0, 'total' => 1, 'tasks_completed' => 0, 'tasks_total' => 1],
    ];
    
    $summary = invokePrivateMethod($renderer, 'summaryRow', $rows, []);

    // It should pick 'OK' from the first row because it's the first filled value.
    expect($summary['meta']['status'])->toBe('OK');
});

it('formats non-numeric values correctly', function () {
    $renderer = new ProgressTableRenderer(columns: [], footer: []);

    expect(invokePrivateMethod($renderer, 'formatValue', 'string'))->toBe('string');
    expect(invokePrivateMethod($renderer, 'formatValue', 1234.56))->toBe('1,235');
    expect(invokePrivateMethod($renderer, 'formatValue', null))->toBe('-');
});

