<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\WalletSplitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletSplitController extends Controller
{
    public function __construct(
        private readonly WalletSplitService $walletSplitService
    ) {}

    /**
     * GET /api/internal/orders/{orderId}/wallet-split?checkout_id=...
     *
     * Used by Wallet Service after payment.completed to split funds by shop + platform commission.
     */
    public function show(Request $request, string $orderId): JsonResponse
    {
        $checkoutId = $request->query('checkout_id');
        if (! is_string($checkoutId) || trim($checkoutId) === '') {
            return response()->json(['message' => 'checkout_id query parameter is required'], 422);
        }

        $payload = $this->walletSplitService->buildForOrder($orderId, $checkoutId);

        return response()->json($payload);
    }
}
