<?php

namespace Uncrackable404\ConcurrentConsoleProgress;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleProgressServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen('Illuminate\Console\Events\CommandStarting', function (object $event): void {
            $output = $event->output ?? null;

            if ($output instanceof OutputInterface) {
                ConcurrentProgress::setOutput($output);
            }
        });

        Event::listen('Illuminate\Console\Events\CommandFinished', function (): void {
            ConcurrentProgress::setOutput(null);
        });
    }
}
