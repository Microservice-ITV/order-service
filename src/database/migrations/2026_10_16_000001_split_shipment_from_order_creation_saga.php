<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ORDER_CREATION_STEPS = 2;

    public function up(): void
    {
        DB::table('saga_states')
            ->where('type', 'ORDER_CREATION')
            ->orderBy('id')
            ->chunkById(200, function ($sagas): void {
                $checkoutIds = $sagas->pluck('order_id')->filter()->unique()->values();
                $checkouts = DB::table('checkout_sessions')
                    ->whereIn('id', $checkoutIds)
                    ->get(['id', 'reservation_id', 'status_id'])
                    ->keyBy('id');
                $orderCheckouts = array_fill_keys(
                    DB::table('orders')->whereIn('checkout_id', $checkoutIds)->pluck('checkout_id')->all(),
                    true,
                );

                foreach ($sagas as $saga) {
                    $completedSteps = is_array($saga->completed_steps)
                        ? $saga->completed_steps
                        : json_decode((string) $saga->completed_steps, true);
                    $completedSteps = is_array($completedSteps) ? $completedSteps : [];

                    // Repair sagas created before the initial checkout event was
                    // published after the saga row. Infer inventory completion
                    // from the durable checkout reservation/status and payment
                    // completion from the order row.
                    $checkout = $checkouts->get($saga->order_id);
                    if ($checkout && (
                        !empty($checkout->reservation_id)
                        || in_array((int) $checkout->status_id, [
                            OrderStatus::STOCK_CONFIRMED->value,
                            OrderStatus::PENDING_PAYMENT->value,
                            OrderStatus::PAID->value,
                            OrderStatus::CONFIRMED->value,
                            OrderStatus::PARTIALLY_FULFILLED->value,
                            OrderStatus::FULFILLED->value,
                            OrderStatus::READY_FOR_FULFILLMENT->value,
                            OrderStatus::SHIPPED->value,
                            OrderStatus::DELIVERED->value,
                        ], true)
                    )) {
                        $completedSteps[] = 'INVENTORY_RESERVATION';
                    }
                    if (isset($orderCheckouts[$saga->order_id])) {
                        $completedSteps[] = 'PAYMENT_PROCESSING';
                    }

                    $completedSteps = array_values(array_unique(array_filter(
                        $completedSteps,
                        static fn ($step): bool => in_array($step, ['INVENTORY_RESERVATION', 'PAYMENT_PROCESSING'], true),
                    )));
                    $hasInventory = in_array('INVENTORY_RESERVATION', $completedSteps, true);
                    $hasPayment = in_array('PAYMENT_PROCESSING', $completedSteps, true);
                    $isOrderPlacementComplete = $hasInventory && $hasPayment;

                    $update = [
                        'total_steps' => self::ORDER_CREATION_STEPS,
                        'completed_steps' => json_encode($completedSteps, JSON_THROW_ON_ERROR),
                        'current_step' => $isOrderPlacementComplete
                            ? self::ORDER_CREATION_STEPS
                            : count($completedSteps) + 1,
                        'updated_at' => now(),
                    ];

                    if (
                        $isOrderPlacementComplete
                        && in_array($saga->status, ['PENDING', 'IN_PROGRESS'], true)
                    ) {
                        $update['status'] = 'COMPLETED';
                        $update['current_step'] = self::ORDER_CREATION_STEPS;
                        $update['completed_at'] = $saga->completed_at ?? now();
                    }

                    DB::table('saga_states')
                        ->where('id', $saga->id)
                        ->update($update);
                }
            });
    }

    public function down(): void
    {
        DB::table('saga_states')
            ->where('type', 'ORDER_CREATION')
            ->update([
                'total_steps' => 3,
                'updated_at' => now(),
            ]);
    }
};
