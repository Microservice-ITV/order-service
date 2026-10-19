<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelOrderRequest;
use App\Services\OrderLifecycleService;
use Illuminate\Http\JsonResponse;

final class CancelShippedOrderController extends Controller
{
    public function __construct(private readonly OrderLifecycleService $orders) {}

    public function __invoke(CancelOrderRequest $request, string $orderId): JsonResponse
    {
        try {
            $order = $this->orders->cancelShippedOrder($orderId, $request->validated('reason'));
        } catch (BusinessException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
                ...$exception->getData(),
            ], $exception->getStatus());
        }

        return response()->json([
            'order_id' => $order->order_id,
            'status' => OrderStatus::tryFrom((int) $order->order_status)?->name,
            'cancellation_reason' => $order->cancellation_reason,
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'return_deadline_at' => $order->return_deadline_at?->toIso8601String(),
        ]);
    }
}
