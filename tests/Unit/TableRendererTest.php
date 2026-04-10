<?php

use Uncrackable404\ConcurrentConsoleProgress\Output\TableRenderer;

it('trims values to empty string when width is zero or less', function () {
    $renderer = new TableRenderer([]);
    
    $reflection = new ReflectionClass(TableRenderer::class);
    $method = $reflection->getMethod('trimToWidth');
    
    expect($method->invoke($renderer, 'Long Value', 0))->toBe('')
        ->and($method->invoke($renderer, 'Long Value', -1))->toBe('');
});
