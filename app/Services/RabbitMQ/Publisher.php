<?php

namespace App\Services\RabbitMQ;

use App\Contracts\EventPublisherInterface;
use App\Enums\EventTopic;
use PhpAmqpLib\Message\AMQPMessage;

class Publisher implements EventPublisherInterface
{
    public function publish(EventTopic $topic, array $payload): void
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

    /**
     * Static facade for backward compatibility. Prefer injecting EventPublisherInterface.
     */
    public static function publishStatic(EventTopic $topic, array $payload): void
    {
        app(EventPublisherInterface::class)->publish($topic, $payload);
    }
}
