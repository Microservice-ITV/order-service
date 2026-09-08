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
                        $ch->basic_ack($msg->getDeliveryTag());
                        return;
                    }

                    $eventId = $payload['event_id'] ?? 'none';
                    $self->info("[{$name}] RECEIVED event_id={$eventId} queue={$queue}");

                    try {
                        $self->info("[{$name}] resolving handler...");
                        $handler = app($handlerClass);
                        $self->info("[{$name}] calling process()...");
                        $handler->process($payload);
                        $self->info("[{$name}] process() DONE, acking...");
                        $ch->basic_ack($msg->getDeliveryTag());
                        $self->info("[{$name}] acked OK");
                    } catch (Throwable $e) {
                        $self->error("[{$name}] FAILED: {$e->getMessage()}");
                        logger()->error('INLINE_CONSUMER_FAILED', [
                            'consumer' => $name,
                            'queue' => $queue,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        $ch->basic_nack($msg->getDeliveryTag(), false, true);
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
                        // Wait up to 5 seconds for activity on the channel to reduce noisy timeouts
                        $ch->wait(null, false, 5);
                    } catch (AMQPConnectionClosedException $e) {
                        $this->error("Connection lost on {$name}: {$e->getMessage()}");
                        throw $e;
                    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                        // Normal timeout waiting for messages; not an error — continue loop
                        continue;
                    } catch (Throwable $e) {
                        // Some network-timeout-like errors are surfaced as generic Throwables with
                        // messages like "The connection timed out after 1 sec while awaiting incoming data".
                        // Treat those as informational (they indicate no activity) rather than errors.
                        $msg = $e->getMessage();
                        if (stripos($msg, 'timed out') !== false || stripos($msg, 'awaiting incoming data') !== false) {
                            // log at debug level if available, otherwise as a comment
                            $this->comment("Transient channel timeout on {$name}: {$msg}");
                            continue;
                        }

                        $this->error("Channel error on {$name}: {$msg}");
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
