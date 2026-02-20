<?php

namespace App\Services\RabbitMQ;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitMQConnection
{
    public static function make(): AMQPStreamConnection
    {
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

        return new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password'),
            config('rabbitmq.vhost'),
            false,
            'PLAIN',     // REQUIRED by CloudAMQP
            null,
            'en_US',
            5.0,
            5.0,
            $context
        );
    }
}
