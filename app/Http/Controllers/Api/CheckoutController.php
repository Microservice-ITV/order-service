<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\UseCases\Checkout\StartCheckout;
use App\Exceptions\BusinessException;
use App\Models\CheckoutSession;
use App\Enums\OrderStatus;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function checkout(CheckoutRequest $request, StartCheckout $useCase): JsonResponse
    {
        try {
            $result = $useCase->handle(
                items: $request->validated('items'),
                userId: $request->validated('user_id'),
                rawIdempotencyKey: $request->validated('idempotency_key')
            );
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatus(), $e->getData());
        }

        if ($result->existing) {
            return $this->existingOrderResponse($result->checkout);
        }

        return $this->successResponse($result->checkout->id, $result->checkout->getExpireAtIso());
    }

    private function existingOrderResponse(CheckoutSession $checkout): JsonResponse
    {
        return response()->json([
            'checkout_id' => $checkout->id,
            'status'      => $checkout->status_id,
            'message'     => match ($checkout->status_id) {
                OrderStatus::PAID->value => 'Order already paid',
                OrderStatus::CANCELLED->value => 'Order was cancelled',
                default => 'Order already received and is processing',
            }
        ]);
    }


    private function successResponse(string $checkoutId, ?string $expireAt): JsonResponse
    {
        return response()->json([
            'checkout_id' => $checkoutId,
            'message' => 'Order received and we are processing your order',
            'expire_at' => $expireAt,
        ]);
    }

    private function errorResponse(string $message, int $status = 400, array $data = []): JsonResponse
    {
        return response()->json([
            'error' => $message,
            ...$data,
        ], $status);
    }
}
