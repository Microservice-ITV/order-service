<?php

namespace App\Console\Commands;

use App\Services\RabbitMQ\SagaConsumer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Saga Management Command
 * 
 * Handles saga timeout processing and cleanup operations.
 */
class SagaManageCommand extends Command
{
    protected $signature = 'saga:manage 
                           {action : Action to perform (process-timeouts|cleanup-old-sagas|statistics)}
                           {--days=7 : Number of days to keep sagas for cleanup}
                           {--limit=100 : Maximum number of sagas to process at once}';

    protected $description = 'Manage saga lifecycle operations';

    private SagaConsumer $sagaConsumer;

    public function __construct(SagaConsumer $sagaConsumer)
    {
        parent::__construct();
        $this->sagaConsumer = $sagaConsumer;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');
        
        switch ($action) {
            case 'process-timeouts':
                return $this->processTimeouts();
                
            case 'cleanup-old-sagas':
                return $this->cleanupOldSagas();
                
            case 'statistics':
                return $this->showStatistics();
                
            default:
                $this->error("Unknown action: {$action}");
                return self::INVALID_ARGUMENT;
        }
    }

    /**
     * Process timed out sagas
     */
    private function processTimeouts(): int
    {
        $this->info('Processing saga timeouts...');
        
        try {
            $processedCount = 0;
            $timeoutLimit = (int) $this->option('limit');
            
            // Process timeouts multiple times to handle cascading failures
            for ($i = 0; $i < 3; $i++) {
                $this->sagaConsumer->processTimeouts();
                $processedCount++;
                
                if ($processedCount >= $timeoutLimit) {
                    break;
                }
                
                // Small delay between batches
                usleep(500000); // 0.5 seconds
            }
            
            $this->info("Processed {$processedCount} timeout(s)");
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to process timeouts: {$e->getMessage()}");
            Log::error('Saga timeout processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Cleanup old sagas
     */
    private function cleanupOldSagas(): int
    {
        $days = (int) $this->option('days');
        $this->info("Cleaning up sagas older than {$days} days...");
        
        try {
            $deletedCount = $this->sagaConsumer->cleanupOldSagas($days);
            
            $this->info("Cleaned up {$deletedCount} old saga(s)");
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to cleanup old sagas: {$e->getMessage()}");
            Log::error('Saga cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Show saga statistics
     */
    private function showStatistics(): int
    {
        try {
            $stats = $this->sagaConsumer->getSagaStatistics();
            
            $this->table(['Metric', 'Value'], [
                ['Total Sagas', $stats['total_sagas']],
                ['Active Sagas', $stats['active_sagas']],
                ['Completed Sagas', $stats['completed_sagas']],
                ['Failed Sagas', $stats['failed_sagas']],
                ['Compensating Sagas', $stats['compensating_sagas']],
                ['Success Rate', $stats['success_rate'] . '%'],
            ]);
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to get statistics: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}