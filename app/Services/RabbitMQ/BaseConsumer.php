<?php

namespace App\Services\RabbitMQ;

abstract class BaseConsumer
{
    abstract protected function routingKeys(): array;
    abstract protected function process(array $payload): void;

    protected function queue(): string
    {
        return config('rabbitmq.queues.product');
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
                $this->process(json_decode($msg->body, true));
            }
        );

        echo "Listening on queue: {$this->queue()}\n";

        // ✅ THIS IS THE CORRECT LOOP
        while (true) {
            $channel->wait();
        }
    }
}
