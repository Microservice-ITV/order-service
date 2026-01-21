<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\ConsumeProductValidated;
use App\Console\Commands\ConsumeProductRejected;
use App\Console\Commands\ConsumePaymentSucceeded;
use App\Console\Commands\ConsumePaymentFailed;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        ConsumeProductValidated::class,
        ConsumeProductRejected::class,
        ConsumePaymentSucceeded::class,
        ConsumePaymentFailed::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // optional
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
    }
}
