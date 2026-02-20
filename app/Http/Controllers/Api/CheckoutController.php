<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\CheckoutSession;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService
    ) {}

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        try {
            $result = $this->checkoutService->startCheckout(
                items: $request->validated('items'),
                userId: $request->validated('user_id'),
                rawIdempotencyKey: $request->validated('idempotency_key')
            );
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatus(), $e->getData());
        }

        $checkout = $result['checkout'];
        if ($result['existing']) {
            return $this->existingOrderResponse($checkout);
        }

        return $this->successResponse($checkout->id, $checkout->getExpireAtIso());
    }

    private function existingOrderResponse(CheckoutSession $checkout): JsonResponse
    {
        return response()->json([
            'checkout_id' => $checkout->id,
            'status'      => $checkout->status_id->value,
            'message'     => match ($checkout->status_id) {
                OrderStatus::PAID => 'Order already paid',
                OrderStatus::CANCELLED => 'Order was cancelled',
                default => 'Order already received and is processing',
            },
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
