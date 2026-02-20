<?php

namespace App\Services\RabbitMQ;

use App\Enums\EventTopic;
use PhpAmqpLib\Message\AMQPMessage;

class Publisher
{
    public static function publish(EventTopic $topic, array $payload): void
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

        $msg = new AMQPMessage(json_encode($payload), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $channel->basic_publish(
            $msg,
            config('rabbitmq.exchange'),
            $topic->value
        );

        $channel->close();
        $conn->close();
    }
}
