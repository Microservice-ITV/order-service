<?php

namespace App\Services\RabbitMQ;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class BaseConsumer extends Command
{
    abstract protected function routingKeys(): array;
    abstract protected function queue(): string;
    abstract public function process(array $payload): void;

    public function handle(): void
    {
        $this->listen();
    }

    public function listen(): void
    {
        $conn = RabbitMQConnection::make();
        try {
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
                false,
                false,
                false,
                function ($msg) use ($channel) {
                    $payload = json_decode($msg->body, true);
                    if (!is_array($payload)) {
                        $channel->basic_ack($msg->delivery_tag);
                        return;
                    }

                    $eventId = (string) ($payload['event_id'] ?? '');
                    if ($eventId === '') {
                        try {
                            $this->process($payload);
                            $channel->basic_ack($msg->delivery_tag);
                        } catch (Throwable $e) {
                            logger()->error('CONSUMER_PROCESS_FAILED_NO_EVENT_ID', [
                                'consumer' => static::class,
                                'error' => $e->getMessage(),
                            ]);
                            $channel->basic_nack($msg->delivery_tag, false, true);
                        }
                        return;
                    }

                    $eventType = (string) ($payload['event'] ?? '');
                    $this->trackInboxReceived($eventId, $eventType, $payload);

                    if ($this->isAlreadyProcessed($eventId)) {
                        $this->trackInboxSkippedDuplicate($eventId);
                        logger()->info('EVENT_SKIPPED_ALREADY_PROCESSED', [
                            'event_id' => $eventId,
                            'consumer' => static::class,
                        ]);
                        $channel->basic_ack($msg->delivery_tag);
                        return;
                    }

                    $this->markProcessing($eventId, $eventType);
                    $this->trackInboxProcessing($eventId);
                    try {
                        $this->process($payload);
                        $this->markProcessed($eventId);
                        $this->trackInboxProcessed($eventId);
                        $channel->basic_ack($msg->delivery_tag);
                    } catch (Throwable $e) {
                        $this->markFailed($eventId, $e->getMessage());
                        $this->trackInboxFailed($eventId, $e->getMessage());
                        $channel->basic_nack($msg->delivery_tag, false, true);
                    }
                }
            );

            echo "Listening on queue: {$this->queue()}\n";

            while (true) {
                $channel->wait();
            }
        } catch (Throwable $e) {
            logger()->error('CONSUMER_FATAL_ERROR', [
                'consumer' => static::class,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            try {
                if (isset($channel) && $channel->is_open()) {
                    $channel->close();
                }
            } catch (Throwable $e) {
                // ignore close errors
            }
            try {
                if (isset($conn) && $conn->is_connected()) {
                    $conn->close();
                }
            } catch (Throwable $e) {
                // ignore close errors
            }
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

    private function trackInboxReceived(string $eventId, string $eventType, array $payload): void
    {
        $consumer = static::class;
        $existing = DB::table('inbox_events')
            ->where('event_id', $eventId)
            ->where('consumer', $consumer)
            ->first(['id', 'receive_count']);

        if ($existing) {
            DB::table('inbox_events')
                ->where('id', $existing->id)
                ->update([
                    'receive_count' => (int) $existing->receive_count + 1,
                    'last_received_at' => now(),
                    'updated_at' => now(),
                ]);
            return;
        }

        DB::table('inbox_events')->insert([
            'event_id' => $eventId,
            'event_type' => $eventType !== '' ? $eventType : null,
            'consumer' => $consumer,
            'payload' => json_encode($payload),
            'status' => 'RECEIVED',
            'receive_count' => 1,
            'first_received_at' => now(),
            'last_received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function trackInboxProcessing(string $eventId): void
    {
        DB::table('inbox_events')
            ->where('event_id', $eventId)
            ->where('consumer', static::class)
            ->update([
                'status' => 'PROCESSING',
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    private function trackInboxProcessed(string $eventId): void
    {
        DB::table('inbox_events')
            ->where('event_id', $eventId)
            ->where('consumer', static::class)
            ->update([
                'status' => 'PROCESSED',
                'processed_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    private function trackInboxSkippedDuplicate(string $eventId): void
    {
        DB::table('inbox_events')
            ->where('event_id', $eventId)
            ->where('consumer', static::class)
            ->update([
                'status' => 'SKIPPED_DUPLICATE',
                'updated_at' => now(),
            ]);
    }

    private function trackInboxFailed(string $eventId, string $error): void
    {
        DB::table('inbox_events')
            ->where('event_id', $eventId)
            ->where('consumer', static::class)
            ->update([
                'status' => 'FAILED',
                'last_error' => mb_substr($error, 0, 2000),
                'updated_at' => now(),
            ]);
    }
}
