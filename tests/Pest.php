<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function getPrivateProperty(object $target, string $property): mixed
{
    return (function () use ($property): mixed {
        return $this->{$property};
    })->call($target);
}

function invokePrivateMethod(object $target, string $method, mixed ...$arguments): mixed
{
    return (function (mixed ...$arguments) use ($method): mixed {
        return $this->{$method}(...$arguments);
    })->call($target, ...$arguments);
}

function setPrivateProperty(object $target, string $property, mixed $value): void
{
    (function (mixed $value) use ($property): void {
        $this->{$property} = $value;
    })->call($target, $value);
}

function exceptionWithCallbackTrace(): RuntimeException
{
    try {
        throwExceptionFromCallbackTrace(function (): void {});
    } catch (RuntimeException $exception) {
        return $exception;
    }

    throw new RuntimeException('Failed to create test exception');
}

function throwExceptionFromCallbackTrace(Closure $callback): void
{
    throw new RuntimeException('Request failed');
}

function cleanConsoleLines(string $frame): array
{
    return array_map(
        fn (string $line): string => preg_replace('/<[^>]+>/', '', $line) ?? $line,
        explode(PHP_EOL, $frame),
    );
}

function cleanConsoleLine(string $frame, string $needle): string
{
    foreach (cleanConsoleLines($frame) as $line) {
        if (str_contains($line, $needle)) {
            return $line;
        }
    }

    throw new RuntimeException('Unable to find console line for needle: ' . $needle);
}

function progressColumnOffset(string $frame, string $needle): int
{
    $offset = mb_strpos(cleanConsoleLine($frame, $needle), '[');

    if ($offset === false) {
        throw new RuntimeException('Unable to find progress bar for needle: ' . $needle);
    }

    return $offset;
}
