<?php

namespace Uncrackable404\ConcurrentConsoleProgress;

use Closure;

if (! function_exists('\Uncrackable404\ConcurrentConsoleProgress\concurrent')) {
    function concurrent(
        array $queues,
        array $tasks,
        int $concurrency,
        Closure $process,
        array $columns = [],
        array $footer = [],
        ?Closure $before = null,
    ): array {
        return (new ConcurrentProgress)->run(
            queues: $queues,
            tasks: $tasks,
            concurrency: $concurrency,
            process: $process,
            columns: $columns,
            footer: $footer,
            before: $before,
        );
    }
}
