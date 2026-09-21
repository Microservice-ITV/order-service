<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SagaStateSeeder extends Seeder
{
    public function run(): void
    {  
        $sagaIds = ['70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000002'];
        DB::table('saga_states')->whereIn('saga_id', $sagaIds)->delete();
        $base = OrderSeedData::baseTime();
        $this->insertSaga($sagaIds[0], OrderSeedData::CHECKOUTS['success'], ['INVENTORY_RESERVATION', 'PAYMENT_PROCESSING', 'SHIPMENT'], [], 'COMPLETED', $base);
        $this->insertSaga($sagaIds[1], OrderSeedData::CHECKOUTS['failed_payment'], ['INVENTORY_RESERVATION'], ['PAYMENT_PROCESSING'], 'FAILED', $base->addMinutes(20));
    }

    private function insertSaga(string $sagaId, string $checkoutId, array $completed, array $failed, string $status, $createdAt): void
    {
        DB::table('saga_states')->insert([
            'saga_id' => $sagaId,
            'type' => 'ORDER_CREATION',
            'status' => $status,
            'order_id' => $checkoutId,
            'correlation_id' => OrderSeedData::correlation($checkoutId),
            'data' => json_encode(['checkout_id' => $checkoutId, 'user_id' => 'seed-user']),
            'step_order' => count($completed) + count($failed) + 1,
            'total_steps' => 3,
            'current_step' => count($completed) + 1,
            'completed_steps' => json_encode($completed),
            'failed_steps' => json_encode($failed),
            'compensation_required' => $failed !== [],
            'compensation_status' => $failed !== [] ? 'COMPLETED' : null,
            'error_message' => $failed !== [] ? 'CARD_DECLINED' : null,
            'started_at' => $createdAt,
            'completed_at' => $createdAt->addMinutes(40),
            'timeout_at' => $createdAt->addMinutes(30),
            'created_at' => $createdAt,
            'updated_at' => $createdAt->addMinutes(40),
        ]);
    }
}
