<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReturnOrderRequest;
use App\Services\OrderLifecycleService;
use Illuminate\Http\JsonResponse;

final class MarkOrderReturnedController extends Controller
{
    public function __construct(private readonly OrderLifecycleService $orders) {}

    public function __invoke(ReturnOrderRequest $request, string $orderId): JsonResponse
    {
        try {
            $order = $this->orders->markOrderReturned($orderId, $request->validated('reason'));
        } catch (BusinessException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
                ...$exception->getData(),
            ], $exception->getStatus());
        }

        return response()->json([
            'order_id' => $order->order_id,
            'status' => OrderStatus::tryFrom((int) $order->order_status)?->name,
            'return_reason' => $order->return_reason,
            'returned_at' => $order->returned_at?->toIso8601String(),
        ]);
    }
}
