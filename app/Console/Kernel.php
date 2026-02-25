<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\ConsumeProductValidated;
use App\Console\Commands\ConsumeProductRejected;
use App\Console\Commands\ConsumePaymentSucceeded;
use App\Console\Commands\ConsumePaymentFailed;
use App\Console\Commands\ConsumeAll;
use App\Console\Commands\PublishOutboxPending;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        ConsumeProductValidated::class,
        ConsumeProductRejected::class,
        ConsumePaymentSucceeded::class,
        ConsumePaymentFailed::class,
        ConsumeAll::class,
        PublishOutboxPending::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('outbox:publish --limit=200')->everyMinute();
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
    }
}
