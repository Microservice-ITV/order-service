<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutSessionController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService
    ) {}

    public function show(string $checkoutId): JsonResponse
    {
        $checkout = $this->checkoutService->findById($checkoutId);

        if (!$checkout) {
            return response()->json([
                'error' => 'Checkout session not found',
            ], 404);
        }

        return response()->json([
            'checkout_id' => $checkout->id,
            'status'      => $checkout->status_id->name,
            'payment'     => $checkout->payment_id ? [
                'payment_id'    => $checkout->payment_id,
                'client_secret' => $checkout->client_secret,
            ] : null,
        ]);
    }

    public function cancel(Request $request, string $checkoutId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->checkoutService->cancelByUser(
            checkoutId: $checkoutId,
            reason: $validated['reason'] ?? null
        );

        if ($result['not_found'] === true) {
            return response()->json([
                'error' => 'Checkout session not found',
            ], 404);
        }

        if ($result['already_cancelled'] === true) {
            return response()->json([
                'checkout_id' => $checkoutId,
                'status' => 'CANCELLED',
                'message' => 'Order already cancelled',
            ]);
        }

        if ($result['invalid_state'] === true) {
            return response()->json([
                'checkout_id' => $checkoutId,
                'error' => 'Order cannot be cancelled in current state',
                'status' => $result['status'],
            ], 409);
        }

        return response()->json([
            'checkout_id' => $checkoutId,
            'status' => 'CANCELLED',
            'message' => 'Order cancelled successfully',
        ]);
    }
}
