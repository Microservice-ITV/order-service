<?php

namespace App\Console\Commands;

use App\Services\RabbitMQ\RabbitMQConnection;
use Illuminate\Console\Command;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class ConsumeAll extends Command
{
    protected $signature = 'consume:all';
    protected $description = 'Run all RabbitMQ consumers on a single connection (1 conn, 5 channels)';

    private array $consumers = [];
    private array $queues = [];
    private array $exchange;

    public function handle(): int
    {
        $this->exchange = [
            'name' => config('rabbitmq.exchange'),
            'type' => 'topic',
        ];

        $this->consumers = [
            'consume:stock-reserved' => [
                'queue' => config('rabbitmq.queues.stock_reserved'),
                'routing_keys' => ['product.stock.reserved'],
                'handler' => ConsumeProductValidated::class,
            ],
            'consume:stock-rejected' => [
                'queue' => config('rabbitmq.queues.stock_rejected'),
                'routing_keys' => ['product.stock.rejected'],
                'handler' => ConsumeProductRejected::class,
            ],
            'consume:stock-confirmed' => [
                'queue' => config('rabbitmq.queues.stock_confirmed'),
                'routing_keys' => ['product.stock.confirmed'],
                'handler' => ConsumeProductStockConfirmed::class,
            ],
            'consume:payment-succeeded' => [
                'queue' => config('rabbitmq.queues.payment_completed'),
                'routing_keys' => ['payment.completed'],
                'handler' => ConsumePaymentSucceeded::class,
            ],
            'consume:payment-failed' => [
                'queue' => config('rabbitmq.queues.payment_failed'),
                'routing_keys' => ['payment.failed'],
                'handler' => ConsumePaymentFailed::class,
            ],
        ];

        $this->info('Connecting to RabbitMQ (single connection)...');

        try {
            $conn = RabbitMQConnection::make();
        } catch (Throwable $e) {
            $this->error('Failed to connect: ' . $e->getMessage());
            return 1;
        }

        $exchangeName = $this->exchange['name'];
        $channels = [];
        $queueNames = [];

        foreach ($this->consumers as $name => $config) {
            $queue = $config['queue'];
            $queueNames[] = $queue;

            $ch = $conn->channel();

            $ch->exchange_declare($exchangeName, 'topic', false, true, false);
            $ch->queue_declare($queue, false, true, false, false);

            foreach ($config['routing_keys'] as $key) {
                $ch->queue_bind($queue, $exchangeName, $key);
            }

            $handlerClass = $config['handler'];
            $self = $this;

            $ch->basic_consume(
                $queue,
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $msg) use ($ch, $handlerClass, $self, $name, $queue) {
                    $payload = json_decode($msg->body, true);
                    if (!is_array($payload)) {
                        $ch->basic_ack($msg->delivery_tag);
                        return;
                    }

                    try {
                        $handler = app($handlerClass);
                        $handler->process($payload);
                        $ch->basic_ack($msg->delivery_tag);
                    } catch (Throwable $e) {
                        logger()->error('INLINE_CONSUMER_FAILED', [
                            'consumer' => $name,
                            'queue' => $queue,
                            'error' => $e->getMessage(),
                        ]);
                        $ch->basic_nack($msg->delivery_tag, false, true);
                    }
                }
            );

            $channels[$name] = $ch;
            $this->info("  [{$name}] listening on {$queue}");
        }

        $this->newLine();
        $this->info('All consumers running on single connection!');
        $this->comment('Press Ctrl+C to stop');
        $this->newLine();

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () use ($channels, $conn) {
            $this->warn('Shutting down...');
            foreach ($channels as $ch) {
                try { $ch->close(); } catch (Throwable $e) {}
            }
            try { $conn->close(); } catch (Throwable $e) {}
            exit(0);
        });
        pcntl_signal(SIGTERM, function () use ($channels, $conn) {
            foreach ($channels as $ch) {
                try { $ch->close(); } catch (Throwable $e) {}
            }
            try { $conn->close(); } catch (Throwable $e) {}
            exit(0);
        });

        try {
            while (true) {
                foreach ($channels as $name => $ch) {
                    try {
                        $ch->wait();
                    } catch (AMQPConnectionClosedException $e) {
                        $this->error("Connection lost on {$name}: {$e->getMessage()}");
                        throw $e;
                    } catch (Throwable $e) {
                        $this->error("Channel error on {$name}: {$e->getMessage()}");
                    }
                }
            }
        } catch (Throwable $e) {
            $this->error('Fatal: ' . $e->getMessage());
            foreach ($channels as $ch) {
                try { $ch->close(); } catch (Throwable $x) {}
            }
            try { $conn->close(); } catch (Throwable $x) {}
            return 1;
        }

        return 0;
    }
}
