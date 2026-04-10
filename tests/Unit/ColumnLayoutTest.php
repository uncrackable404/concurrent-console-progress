<?php

use Uncrackable404\ConcurrentConsoleProgress\Output\ColumnLayout;

it('shrinks columns to their minimum width when overflowing', function () {
    $layout = new ColumnLayout();
    
    $columns = [
        ['label' => 'Long Label'], // natural width is 10. min_width is 10.
    ];
    
    // Natural width of row is 15. So it will try to shrink from 15 to 10.
    $rows = [['Value Is Long Now']]; // width 17.
    
    // naturalWidth = 17. labelWidth = 10. widths[0] = 17.
    // availableWidth = 5.
    // overflow = 12.
    // shrink will run. it will decrease widths[0] until 10.
    // Then it will break.
    
    $widths = $layout->resolve($columns, $rows, 5);
    
    expect($widths[0])->toBe(10);
});

it('uses explicit width as minimum width', function () {
    $layout = new ColumnLayout();
    
    $columns = [['label' => 'L', 'width' => 10]];
    
    // minimumWidth for this column should be 10.
    $reflection = new ReflectionClass(ColumnLayout::class);
    $method = $reflection->getMethod('minimumWidth');
    
    expect($method->invoke($layout, $columns[0]))->toBe(10);
});

it('measures escaped console markup as literal text', function () {
    expect(ColumnLayout::width('\<error\>danger\</error\>'))
        ->toBe(mb_strwidth('<error>danger</error>'));
});
