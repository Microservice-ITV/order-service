<?php

namespace App\Services\RabbitMQ;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

abstract class BaseConsumer extends Command
{
    /**
     * Return array of routing keys to listen for
     */
    abstract protected function routingKeys(): array;

    /**
     * Return queue name for this consumer
     */
    abstract protected function queue(): string;

    /**
     * Process the incoming message payload
     */
    abstract protected function process(array $payload): void;

    /**
     * Execute the console command
     */
    public function handle(): void
    {
        Log::info("Starting consumer: {$this->signature}", [
            'queue'     => $this->queue(),
            'routing_keys' => $this->routingKeys(),
        ]);

        $this->listen();
    }

    /**
     * Listen to RabbitMQ queue and process messages
     */
    public function listen(): void
    {
        try {
            $conn = RabbitMQConnection::make();
            $channel = $conn->channel();

            // Declare exchange
            $channel->exchange_declare(
                config('rabbitmq.exchange'),
                'topic',
                false,
                true,
                false
            );

            // Declare queue
            $channel->queue_declare(
                $this->queue(),
                false,
                true,
                false,
                false
            );

            // Bind queue to exchange with routing keys
            foreach ($this->routingKeys() as $key) {
                $channel->queue_bind(
                    $this->queue(),
                    config('rabbitmq.exchange'),
                    $key
                );
                Log::debug("Bound routing key: {$key}");
            }

            // Set up consumer with error handling
            $channel->basic_consume(
                $this->queue(),
                '',
                false,
                true,
                false,
                false,
                function ($msg) {
                    try {
                        $payload = json_decode($msg->body, true);
                        Log::info("Processing message from queue: {$this->queue()}", [
                            'payload' => $payload,
                        ]);

                        $this->process($payload);

                        Log::info("Message processed successfully");
                    } catch (\Exception $e) {
                        Log::error("Error processing message", [
                            'error'   => $e->getMessage(),
                            'trace'   => $e->getTraceAsString(),
                        ]);
                        // In production, you might want to implement dead-letter queue logic
                    }
                }
            );

            $this->info("🔊 Listening on queue: {$this->queue()}");
            $this->info("📌 Routing keys: " . implode(', ', $this->routingKeys()));

            // Keep consumer running indefinitely
            while (true) {
                try {
                    $channel->wait();
                } catch (\Exception $e) {
                    Log::error("Error in channel wait", [
                        'error' => $e->getMessage(),
                    ]);
                    // Reconnect on error
                    sleep(5);
                    $this->listen();
                }
            }
        } catch (\Exception $e) {
            Log::error("Consumer error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
