<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CodCollectionBatch;
use App\Models\CodCollectionBatchOrder;
use App\Models\Order;
use App\Models\ShopOrder;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CollectCodBatchController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shop_id' => ['required', 'string', 'max:64'],
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'string', 'distinct'],
        ]);
        $shopId = trim((string) $data['shop_id']);
        $orderIds = array_values(array_unique(array_map('strval', $data['order_ids'])));
        sort($orderIds, SORT_STRING);

        $selectedOrderIds = $orderIds;
        $existingMemberships = CodCollectionBatchOrder::query()
            ->whereIn('order_id', $selectedOrderIds)
            ->get(['order_id', 'shop_id', 'batch_id']);
        $batch = null;
        if ($existingMemberships->isNotEmpty()) {
            $batchIds = $existingMemberships->pluck('batch_id')->unique();
            if (
                $existingMemberships->count() !== count($selectedOrderIds)
                || $batchIds->count() !== 1
                || $existingMemberships->contains(static fn (CodCollectionBatchOrder $membership): bool => $membership->shop_id !== $shopId)
            ) {
                return response()->json(['error' => 'Selected orders cannot be combined with an existing Collect COD batch.'], 409);
            }
            $batch = CodCollectionBatch::query()->find($batchIds->first());
            if (!$batch) {
                return response()->json(['error' => 'The existing Collect COD batch could not be found.'], 409);
            }
            $orderIds = CodCollectionBatchOrder::query()
                ->where('batch_id', $batch->id)
                ->where('shop_id', $shopId)
                ->orderBy('order_id')
                ->pluck('order_id')
                ->all();
        }

        $shopOrders = ShopOrder::query()
            ->whereIn('order_id', $orderIds)
            ->where('shop_id', $shopId)
            ->get(['order_id', 'shop_id', 'delivery_company_id', 'order_status']);
        if ($shopOrders->count() !== count($orderIds)) {
            throw ValidationException::withMessages(['order_ids' => 'Every order must resolve to exactly one seller shop.']);
        }

        $groups = $shopOrders->map(static fn (ShopOrder $row): array => [
            'shop_id' => trim((string) $row->shop_id),
            'delivery_company_id' => trim((string) $row->delivery_company_id),
        ])->unique(fn (array $row): string => $row['shop_id'] . '|' . $row['delivery_company_id'])->values();
        if ($groups->count() !== 1) {
            throw ValidationException::withMessages(['order_ids' => 'Select delivered orders for one shop and delivery provider.']);
        }

        foreach ($shopOrders as $shopOrder) {
            if ($shopOrder->order_status !== OrderStatus::DELIVERED->value) {
                throw ValidationException::withMessages(['order_ids' => "Order {$shopOrder->order_id} is not delivered for this shop."]);
            }
            if (!trim((string) $shopOrder->delivery_company_id)) {
                throw ValidationException::withMessages(['order_ids' => "Order {$shopOrder->order_id} has no delivery provider."]);
            }
        }

        $orders = Order::query()->whereIn('order_id', $orderIds)->get(['order_id', 'payment_id']);
        if ($orders->count() !== count($orderIds) || $orders->contains(static fn (Order $order): bool => !$order->payment_id)) {
            throw ValidationException::withMessages(['order_ids' => 'Every selected order must have a payment record.']);
        }

        if ($groups[0]['shop_id'] !== $shopId) {
            return response()->json(['error' => 'Selected shop does not match the orders.'], 409);
        }
        $deliveryCompanyId = $groups[0]['delivery_company_id'];
        if ($batch && ($batch->shop_id !== $shopId || $batch->delivery_company_id !== $deliveryCompanyId)) {
            return response()->json(['error' => 'Existing Collect COD batch attribution no longer matches the orders.'], 409);
        }
        $selectionHash = hash('sha256', json_encode([$shopId, $deliveryCompanyId, $orderIds], JSON_THROW_ON_ERROR));
        [$batch, $alreadyCompleted] = DB::transaction(function () use ($batch, $orderIds, $selectionHash, $shopId, $deliveryCompanyId): array {
            $hashedBatch = CodCollectionBatch::query()
                ->where('selection_hash', $selectionHash)
                ->lockForUpdate()
                ->first();
            if ($batch && (!$hashedBatch || $batch->id !== $hashedBatch->id)) {
                abort(409, 'Existing Collect COD batch does not match the selected order group.');
            }
            $batch = $hashedBatch ?? $batch;

            $currentMemberships = CodCollectionBatchOrder::query()
                ->whereIn('order_id', $orderIds)
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->get(['order_id', 'batch_id']);
            if (!$batch && $currentMemberships->isNotEmpty()) {
                abort(409, 'One or more orders already belong to another Collect COD batch.');
            }

            $batch ??= CodCollectionBatch::firstOrCreate(
                ['selection_hash' => $selectionHash],
                [
                    'id' => (string) Str::uuid(),
                    'shop_id' => $shopId,
                    'delivery_company_id' => $deliveryCompanyId,
                    'effective_at' => now()->utc(),
                ],
            );
            if ($batch->shop_id !== $shopId || $batch->delivery_company_id !== $deliveryCompanyId) {
                abort(409, 'Existing Collect COD batch attribution no longer matches the orders.');
            }
            foreach ($orderIds as $orderId) {
                CodCollectionBatchOrder::query()->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batch->id,
                    'order_id' => $orderId,
                    'shop_id' => $shopId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $memberships = CodCollectionBatchOrder::query()
                ->whereIn('order_id', $orderIds)
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->get(['order_id', 'batch_id']);
            if ($memberships->count() !== count($orderIds) || $memberships->contains(static fn (CodCollectionBatchOrder $row): bool => $row->batch_id !== $batch->id)) {
                abort(409, 'One or more orders already belong to another Collect COD batch.');
            }

            return [$batch, $batch->status === 'COMPLETED'];
        });

        if ($alreadyCompleted) {
            return response()->json([
                'batch_id' => $batch->id,
                'shop_id' => $batch->shop_id,
                'delivery_company_id' => $batch->delivery_company_id,
                'order_ids' => $orderIds,
                'status' => 'COLLECTED',
                'previously_collected' => true,
                'payments' => $orders->map(static fn (Order $order): array => [
                    'paymentId' => $order->payment_id,
                    'orderId' => $order->order_id,
                    'status' => 'COLLECTED',
                    'previouslyCollected' => true,
                ])->all(),
            ]);
        }

        try {
            $result = $this->payments->collectPaymentsBatch([
                'groupId' => $batch->id,
                'shopId' => $batch->shop_id,
                'deliveryCompanyId' => $batch->delivery_company_id,
                'orderIds' => $orderIds,
                'effectiveAt' => $batch->effective_at->toIso8601String(),
            ]);
        } catch (\Throwable $error) {
            return response()->json(['error' => $error->getMessage(), 'batch_id' => $batch->id], 422);
        }

        $payments = $result['payments'] ?? [];
        $allPaymentsCollected = count($payments) === count($orderIds)
            && !array_filter($payments, static fn (array $payment): bool => strtoupper((string) ($payment['status'] ?? '')) !== 'COLLECTED');
        if (!$allPaymentsCollected) {
            return response()->json(['error' => 'Payment Service did not confirm every payment in the batch.', 'batch_id' => $batch->id], 502);
        }

        $batch->forceFill(['status' => 'COMPLETED', 'completed_at' => now()])->save();

        return response()->json([
            'batch_id' => $batch->id,
            'shop_id' => $batch->shop_id,
            'delivery_company_id' => $batch->delivery_company_id,
            'order_ids' => $orderIds,
            'status' => 'COLLECTED',
            'previously_collected' => false,
            'payments' => $payments,
        ]);
    }
}
