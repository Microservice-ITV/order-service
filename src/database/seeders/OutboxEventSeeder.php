<?php

namespace Database\Seeders;

use App\Enums\EventTopic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OutboxEventSeeder extends Seeder
{
    public function run(): void
    {
        $eventIds = array_map(static fn (int $number): string => OrderSeedData::eventId($number), range(1, 6));
        DB::table('outbox_events')->whereIn('event_id', $eventIds)->delete();
        $base = OrderSeedData::baseTime()->addMinutes(50);
        $rows = [
            [EventTopic::SAGA_ORDER_CREATED, OrderSeedData::CHECKOUTS['success'], ['saga_id' => '70000000-0000-0000-0000-000000000001', 'order_id' => OrderSeedData::CHECKOUTS['success']]],
            [EventTopic::ORDER_PAID, OrderSeedData::CHECKOUTS['success'], ['order_id' => OrderSeedData::ORDERS['success'], 'checkout_id' => OrderSeedData::CHECKOUTS['success'], 'payment_id' => OrderSeedData::PAYMENTS['success'], 'reservation_id' => OrderSeedData::RESERVATIONS['success'], 'status' => 'ORDER_PAID', 'amount' => 198.48, 'currency' => 'USD']],
            [EventTopic::ORDER_READY_FOR_FULFILLMENT, OrderSeedData::CHECKOUTS['success'], ['order_id' => OrderSeedData::ORDERS['success'], 'checkout_id' => OrderSeedData::CHECKOUTS['success'], 'reservation_id' => OrderSeedData::RESERVATIONS['success'], 'status' => 'READY_FOR_FULFILLMENT']],
            [EventTopic::PAYMENT_REQUESTED, OrderSeedData::CHECKOUTS['pending_payment'], ['checkout_id' => OrderSeedData::CHECKOUTS['pending_payment'], 'reservation_id' => OrderSeedData::RESERVATIONS['success'], 'amount' => 32.00, 'currency' => 'USD']],
        ];

        foreach ($rows as $index => [$topic, $checkoutId, $data]) {
            $occurredAt = $base->addMinutes($index);
            $eventId = OrderSeedData::eventId($index + 1);
            $payload = [
                'event_id' => $eventId,
                'event' => $topic->value,
                'version' => 'v1',
                'timestamp' => $occurredAt->toIso8601String(),
                'producer' => 'order-service',
                'correlation_id' => OrderSeedData::correlation($checkoutId),
                'data' => $data,
            ];
            DB::table('outbox_events')->insert([
                'event_id' => $eventId,
                'event_type' => $topic->value,
                'exchange' => config('rabbitmq.exchange'),
                'routing_key' => $topic->value,
                'payload' => json_encode($payload),
                'status' => 'PENDING',
                'attempt_count' => 0,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);
        }
    }
}
