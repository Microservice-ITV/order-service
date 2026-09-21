<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CheckoutSessionSeeder extends Seeder
{
    public function run(): void
    {
        $ids = [...array_values(OrderSeedData::CHECKOUTS), '20000000-0000-0000-0000-000000000004'];
        DB::table('checkout_sessions')->whereIn('id', $ids)->delete();
        $base = OrderSeedData::baseTime();

        $this->insertSession(OrderSeedData::CHECKOUTS['success'], OrderSeedData::USERS[0], OrderStatus::PAID->value, OrderSeedData::RESERVATIONS['success'], OrderSeedData::PAYMENTS['success'], 198.48, $base);
        $this->insertSession(OrderSeedData::CHECKOUTS['pending_payment'], OrderSeedData::USERS[1], OrderStatus::PENDING_PAYMENT->value, OrderSeedData::RESERVATIONS['success'], null, 32.00, $base->addMinutes(10));
        $this->insertSession(OrderSeedData::CHECKOUTS['failed_payment'], OrderSeedData::USERS[2], OrderStatus::FAILED_PAYMENT->value, null, OrderSeedData::PAYMENTS['failed'], 32.00, $base->addMinutes(20), 'CARD_DECLINED');
    }

    private function insertSession(string $id, string $userId, int $status, ?string $reservationId, ?string $paymentId, float $total, $createdAt, ?string $failureReason = null): void
    {
        DB::table('checkout_sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'status_id' => $status,
            'correlation_id' => OrderSeedData::correlation($id),
            'reservation_id' => $reservationId,
            'payment_id' => $paymentId,
            'total' => $total,
            'shipping_total' => $id === OrderSeedData::CHECKOUTS['success'] ? 18.49 : 0.00,
            'failure_reason' => $failureReason,
            'idempotency_key' => 'seed-idempotency-' . substr($id, -12),
            'expire_at' => $createdAt->addMinutes(30),
            'client_secret' => $paymentId ? 'seed_client_secret_' . substr($id, -12) : null,
            'delivery_address' => json_encode(['line1' => '42 Market Street', 'city' => 'Austin', 'postal_code' => '78701', 'country' => 'US']),
            'address_updated_at' => $createdAt->addMinutes(5),
            'created_at' => $createdAt,
            'updated_at' => $createdAt->addMinutes(30),
        ]);
    }
}
