<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ConsumeAll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'consume:all';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start all RabbitMQ consumers (stock-reserved, stock-rejected, stock-confirmed, payment-succeeded, payment-failed)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting all RabbitMQ consumers...');
        $this->newLine();

        $commands = [
            'consume:stock-reserved',
            'consume:stock-rejected',
            'consume:stock-confirmed',
            'consume:payment-succeeded',
            'consume:payment-failed',
        ];

        $processes = [];

        // Start all consumer processes
        foreach ($commands as $command) {
            $this->info("Starting: php artisan {$command}");
            
            $process = new Process([PHP_BINARY, 'artisan', $command], base_path());
            $process->start();
            $processes[$command] = $process;
        }

        $this->newLine();
        $this->info('✅ All consumers started successfully!');
        $this->newLine();
        $this->line('Running consumers:');
        foreach ($commands as $command) {
            $this->line("  🔊 " . ucfirst(str_replace('consume:', '', $command)));
        }
        $this->newLine();
        $this->comment('Press Ctrl+C to stop all consumers');
        $this->newLine();

        // Keep processes running and monitor them
        while (true) {
            foreach ($processes as $command => $process) {
                if (!$process->isRunning()) {
                    $this->error("Consumer '{$command}' has stopped unexpectedly!");
                    $this->error($process->getErrorOutput());
                    
                    // Restart the process
                    $this->warn("Restarting: {$command}");
                    $process = new Process([PHP_BINARY, 'artisan', $command], base_path());
                    $process->start();
                    $processes[$command] = $process;
                }
            }
            
            sleep(2);
        }

        return 0;
    }
}
