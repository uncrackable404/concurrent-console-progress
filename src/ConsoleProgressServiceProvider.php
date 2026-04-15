<?php

namespace Uncrackable404\ConcurrentConsoleProgress;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleProgressServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (object $event): void {
            $output = $event->output ?? null;

            if ($output instanceof OutputInterface) {
                ConcurrentProgress::setOutput($output);
            }
        });

        Event::listen(CommandFinished::class, function (): void {
            ConcurrentProgress::setOutput(null);
        });
    }
}
