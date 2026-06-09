<?php

namespace App\Console\Commands;

use App\Services\RabbitMQ\Publisher;
use Illuminate\Console\Command;
use Throwable;

class PublishOutboxPending extends Command
{
    protected $signature = 'outbox:publish {--limit=100 : Max events per run}';
    protected $description = 'Publish pending/failed outbox events';

    public function handle(Publisher $publisher): int
    {
        try {
            $limit = (int) $this->option('limit');
            if ($limit <= 0) {
                $limit = 100;
            }

            $count = $publisher->publishPending($limit);
            $this->info("Published {$count} outbox event(s).");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Outbox publish failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
