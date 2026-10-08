<?php

namespace App\Services\RabbitMQ;

use Illuminate\Support\Str;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Throwable;

class DeliveryCompanyConfigurationRpcClient
{
    private const ROUTING_KEY = 'accounting.delivery-company.configuration.validate';
    private const TIMEOUT_SECONDS = 5.0;

    public function isActive(
        string $shopId,
        string $deliveryCompanyId,
        string $serviceApiKey,
    ): bool {
        if (trim($serviceApiKey) === '') {
            throw new RuntimeException('Accounting Service authentication is not configured');
        }

        $connection = RabbitMQConnection::make();
        $channel = $connection->channel();

        try {
            $exchange = (string) config('rabbitmq.exchange');
            if ($exchange === '') {
                throw new RuntimeException('RabbitMQ exchange is not configured');
            }

            $channel->exchange_declare($exchange, 'topic', false, true, false);
            [$replyQueue] = $channel->queue_declare('', false, false, true, true);
            $correlationId = (string) Str::uuid();
            $response = null;

            $channel->basic_consume(
                $replyQueue,
                '',
                false,
                true,
                false,
                false,
                function (AMQPMessage $message) use (&$response, $correlationId): void {
                    if ($message->get('correlation_id') !== $correlationId) {
                        return;
                    }

                    $decoded = json_decode($message->getBody(), true);
                    if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'ok' || !is_bool($decoded['valid'] ?? null)) {
                        throw new RuntimeException('Accounting returned an invalid delivery-company response');
                    }

                    $response = $decoded['valid'];
                },
            );

            $requestData = [
                'version' => 1,
                'shopId' => $shopId,
                'deliveryCompanyId' => $deliveryCompanyId,
            ];
            $request = new AMQPMessage(json_encode($requestData, JSON_THROW_ON_ERROR), [
                'content_type' => 'application/json',
                'correlation_id' => $correlationId,
                'reply_to' => $replyQueue,
                'expiration' => (string) ((int) (self::TIMEOUT_SECONDS * 1000)),
                'application_headers' => new AMQPTable([
                    'x-service-key' => $serviceApiKey,
                ]),
            ]);

            $channel->basic_publish($request, $exchange, self::ROUTING_KEY);

            $deadline = microtime(true) + self::TIMEOUT_SECONDS;
            while ($response === null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new RuntimeException('Timed out waiting for Accounting delivery-company validation');
                }

                try {
                    $channel->wait(null, false, $remaining);
                } catch (AMQPTimeoutException $error) {
                    throw new RuntimeException(
                        'Timed out waiting for Accounting delivery-company validation',
                        previous: $error,
                    );
                }
            }

            return $response;
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException) {
                throw $error;
            }
            throw new RuntimeException('RabbitMQ delivery-company validation failed', previous: $error);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }
}
