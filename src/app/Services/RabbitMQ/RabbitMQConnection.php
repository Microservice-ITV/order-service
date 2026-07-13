<?php

namespace App\Services\RabbitMQ;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPConnectionException;

class RabbitMQConnection
{
    private static ?AMQPStreamConnection $shared = null;

    public static function make(): AMQPStreamConnection
    {
        if (self::$shared !== null && self::$shared->is_connected()) {
            return self::$shared;
        }

        $context = null;

        if (config('rabbitmq.ssl')) {
            $context = stream_context_create([
                'ssl' => [
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ]
            ]);
        }

        self::$shared = new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password'),
            config('rabbitmq.vhost'),
            false,
            'PLAIN',
            null,
            'en_US',
            5.0,
            5.0,
            $context
        );

        return self::$shared;
    }

    public static function closeShared(): void
    {
        if (self::$shared !== null) {
            try {
                if (self::$shared->is_connected()) {
                    self::$shared->close();
                }
            } catch (\Throwable $e) {
                // ignore close errors
            }
            self::$shared = null;
        }
    }
}
