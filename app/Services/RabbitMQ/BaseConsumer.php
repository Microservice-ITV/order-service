<?php

namespace App\Services\RabbitMQ;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class BaseConsumer extends Command
{
    abstract protected function routingKeys(): array;
    abstract protected function queue(): string;
    abstract protected function process(array $payload): void;

    public function handle(): void
    {
        $this->listen();
    }

    public function listen(): void
    {
        $conn = RabbitMQConnection::make();
        $channel = $conn->channel();

        $channel->exchange_declare(
            config('rabbitmq.exchange'),
            'topic',
            false,
            true,
            false
        );

        $channel->queue_declare(
            $this->queue(),
            false,
            true,
            false,
            false
        );

        foreach ($this->routingKeys() as $key) {
            $channel->queue_bind(
                $this->queue(),
                config('rabbitmq.exchange'),
                $key
            );
        }

        $channel->basic_consume(
            $this->queue(),
            '',
            false,
            true,
            false,
            false,
            function ($msg) {
                $payload = json_decode($msg->body, true);
                if (!is_array($payload)) {
                    return;
                }

                $eventId = (string) ($payload['event_id'] ?? '');
                if ($eventId === '') {
                    // Backward compatibility for old messages without event_id.
                    $this->process($payload);
                    return;
                }

                if ($this->isAlreadyProcessed($eventId)) {
                    logger()->info('EVENT_SKIPPED_ALREADY_PROCESSED', [
                        'event_id' => $eventId,
                        'consumer' => static::class,
                    ]);
                    return;
                }

                $this->markProcessing($eventId, (string) ($payload['event'] ?? null));
                try {
                    $this->process($payload);
                    $this->markProcessed($eventId);
                } catch (Throwable $e) {
                    $this->markFailed($eventId, $e->getMessage());
                    throw $e;
                }
            }
        );

        echo "Listening on queue: {$this->queue()}\n";

        while (true) {
            $channel->wait();
        }
    }

    private function isAlreadyProcessed(string $eventId): bool
    {
        return DB::table('processed_events')
            ->where('event_id', $eventId)
            ->where('status', 'PROCESSED')
            ->exists();
    }

    private function markProcessing(string $eventId, ?string $eventType): void
    {
        DB::table('processed_events')->updateOrInsert(
            ['event_id' => $eventId],
            [
                'event_type' => $eventType,
                'consumer' => static::class,
                'status' => 'PROCESSING',
                'last_error' => null,
                'processed_at' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function markProcessed(string $eventId): void
    {
        DB::table('processed_events')
            ->where('event_id', $eventId)
            ->update([
                'status' => 'PROCESSED',
                'processed_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    private function markFailed(string $eventId, string $error): void
    {
        DB::table('processed_events')
            ->where('event_id', $eventId)
            ->update([
                'status' => 'FAILED',
                'last_error' => mb_substr($error, 0, 2000),
                'updated_at' => now(),
            ]);
    }
}
