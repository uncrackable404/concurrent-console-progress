<?php

use Uncrackable404\ConcurrentConsoleProgress\Support\Value;

it('determines if a value is filled correctly', function () {
    expect(Value::filled(null))->toBeFalse()
        ->and(Value::filled(''))->toBeFalse()
        ->and(Value::filled('  '))->toBeFalse()
        ->and(Value::filled([]))->toBeFalse()
        ->and(Value::filled('filled'))->toBeTrue()
        ->and(Value::filled(['filled']))->toBeTrue()
        ->and(Value::filled(0))->toBeTrue()
        ->and(Value::filled(false))->toBeTrue()
        ->and(Value::filled(new stdClass))->toBeTrue();
});

it('handles Stringable and Countable objects', function () {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return '  ';
        }
    };
    expect(Value::filled($stringable))->toBeFalse();

    $stringableFilled = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'hello';
        }
    };
    expect(Value::filled($stringableFilled))->toBeTrue();

    $countable = new class implements Countable
    {
        public function count(): int
        {
            return 0;
        }
    };
    expect(Value::filled($countable))->toBeFalse();

    $countableFilled = new class implements Countable
    {
        public function count(): int
        {
            return 1;
        }
    };
    expect(Value::filled($countableFilled))->toBeTrue();
});
