<?php

namespace KarimTao\ConcurrentConsoleProgress;

use Closure;

if (! function_exists('\KarimTao\ConcurrentConsoleProgress\concurrent')) {
    function concurrent(
        array $queues,
        array $tasks,
        int $concurrent,
        Closure $process,
        array $columns = [],
        array $footer = [],
        ?Closure $before = null,
    ): array {
        return (new ConcurrentProgress)->run(
            queues: $queues,
            tasks: $tasks,
            concurrent: $concurrent,
            process: $process,
            columns: $columns,
            footer: $footer,
            before: $before,
        );
    }
}
