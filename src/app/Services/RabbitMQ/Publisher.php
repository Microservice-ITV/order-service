<?php

namespace App\Services\RabbitMQ;

use App\Contracts\EventPublisherInterface;
use App\Enums\EventTopic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class Publisher implements EventPublisherInterface
{
    public function publish(EventTopic $topic, array $payload): void
    {
        $payloadWithEventId = $this->ensureEventId($payload);
        $outboxId = $this->storeOutboxEvent($topic, $payloadWithEventId);
        $this->publishOutboxRow($outboxId);
    }

    /**
     * Static facade for backward compatibility. Prefer injecting EventPublisherInterface.
     */
    public static function publishStatic(EventTopic $topic, array $payload): void
    {
        app(EventPublisherInterface::class)->publish($topic, $payload);
    }

    private function ensureEventId(array $payload): array
    {
        if (!isset($payload['event_id']) || !is_string($payload['event_id']) || $payload['event_id'] === '') {
            $payload['event_id'] = (string) Str::uuid();
        }

        return $payload;
    }

    private function storeOutboxEvent(EventTopic $topic, array $payload): int
    {
        return (int) DB::table('outbox_events')->insertGetId([
            'event_id' => (string) $payload['event_id'],
            'event_type' => $topic->value,
            'exchange' => (string) config('rabbitmq.exchange'),
            'routing_key' => $topic->value,
            'payload' => json_encode($payload),
            'status' => 'PENDING',
            'attempt_count' => 0,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function publishPending(int $limit = 100): int
    {
        $rows = DB::table('outbox_events')
            ->whereIn('status', ['PENDING', 'FAILED'])
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        $published = 0;
        foreach ($rows as $row) {
            if ($this->publishOutboxRow((int) $row->id)) {
                $published++;
            }
        }

        return $published;
    }

    private function publishOutboxRow(int $outboxId): bool
    {
        $row = DB::table('outbox_events')->where('id', $outboxId)->first();
        if (!$row) {
            return false;
        }

        $payload = json_decode((string) $row->payload, true);
        if (!is_array($payload)) {
            DB::table('outbox_events')->where('id', $outboxId)->update([
                'status' => 'FAILED',
                'attempt_count' => (int) $row->attempt_count + 1,
                'last_error' => 'Invalid payload JSON',
                'updated_at' => now(),
            ]);
            return false;
        }

        try {
            $conn = RabbitMQConnection::make();
            $channel = $conn->channel();

            $channel->exchange_declare(
                (string) $row->exchange,
                'topic',
                false,
                true,
                false
            );

            $msg = new AMQPMessage(json_encode($payload), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => (string) ($payload['event_id'] ?? ''),
            ]);

            $channel->basic_publish(
                $msg,
                (string) $row->exchange,
                (string) $row->routing_key
            );

            $channel->close();

            DB::table('outbox_events')->where('id', $outboxId)->update([
                'status' => 'SENT',
                'attempt_count' => (int) $row->attempt_count + 1,
                'published_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            DB::table('outbox_events')->where('id', $outboxId)->update([
                'status' => 'FAILED',
                'attempt_count' => (int) $row->attempt_count + 1,
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }
}
