<?php

namespace Uncrackable404\ConcurrentConsoleProgress\Support;

use RuntimeException;
use Spatie\Fork\Fork;
use Throwable;

class FailFastFork extends Fork
{
    public function fail(Throwable|string $reason): never
    {
        if (extension_loaded('posix')) {
            foreach ($this->runningTasks as $task) {
                if ($task->pid() > 0) {
                    @posix_kill($task->pid(), SIGKILL);
                }
            }
        }

        if ($reason instanceof Throwable) {
            throw $reason;
        }

        throw new RuntimeException($reason);
    }
}
