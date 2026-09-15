<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SagaCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Saga Checkout Controller
 * 
 * Handles checkout operations using saga orchestration for eventual consistency.
 */
class SagaCheckoutController extends Controller
{
    private SagaCheckoutService $sagaCheckoutService;

    public function __construct(SagaCheckoutService $sagaCheckoutService)
    {
        $this->sagaCheckoutService = $sagaCheckoutService;
    }

    /**
     * Start a new checkout session with saga orchestration
     */
    public function startCheckout(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|string',
                'items.*.qty' => 'required|integer|min:1',
                'user_id' => 'required|string',
                'delivery_address' => 'nullable|array',
                'idempotency_key' => 'required|string',
                'payment_method' => 'nullable|string|in:card,cod',
            ]);

            $result = $this->sagaCheckoutService->startSagaCheckout(
                $request->input('items'),
                $request->input('user_id'),
                $request->input('idempotency_key'),
                $request->input('delivery_address'),
                (string) ($request->input('payment_method') ?? 'card')
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ], 400);
        }
    }

    /**
     * Complete checkout with saga orchestration
     */
    public function completeCheckout(Request $request, string $checkoutId): JsonResponse
    {
        try {
            $request->validate([
                'payment_data' => 'nullable|array',
            ]);

            $result = $this->sagaCheckoutService->completeSagaCheckout(
                $checkoutId,
                $request->input('payment_data')
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ], 400);
        }
    }

    /**
     * Get saga status
     */
    public function getSagaStatus(string $sagaId): JsonResponse
    {
        try {
            $status = $this->sagaCheckoutService->getSagaStatus($sagaId);

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ], 404);
        }
    }

    /**
     * Get saga statistics
     */
    public function getSagaStatistics(): JsonResponse
    {
        try {
            $statistics = $this->sagaCheckoutService->getSagaStatistics();

            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ], 500);
        }
    }

    /**
     * Get checkout session status
     */
    public function getCheckoutStatus(string $checkoutId): JsonResponse
    {
        try {
            $checkout = $this->sagaCheckoutService->getCheckoutService()->validateCheckout($checkoutId);
            
            if (!$checkout) {
                return response()->json([
                    'success' => false,
                    'error' => 'Checkout session not found or expired',
                ], 404);
            }

            $saga = $this->sagaCheckoutService->getSagaRepository()->findByOrderId($checkoutId);
            
            // Refresh the saga model to get the latest data from database
            if ($saga) {
                $saga->refresh();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'checkout' => $checkout,
                    'saga' => $saga ? [
                        'saga_id' => $saga->saga_id,
                        'status' => $saga->status,
                        'current_step' => $saga->current_step,
                        'total_steps' => $saga->total_steps,
                        'completed_steps' => $saga->completed_steps ?? [],
                        'failed_steps' => $saga->failed_steps ?? [],
                    ] : null,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ], 500);
        }
    }
}