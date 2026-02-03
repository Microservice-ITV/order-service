<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

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
}
