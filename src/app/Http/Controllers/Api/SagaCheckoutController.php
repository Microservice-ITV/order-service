<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutSession;
use App\Services\CheckoutService;
use App\Services\SagaCheckoutService;
use App\Services\TaxQuoteService;
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

    public function __construct(
        SagaCheckoutService $sagaCheckoutService,
        private readonly CheckoutService $checkoutService,
        private readonly TaxQuoteService $taxQuoteService,
    ) {
        $this->sagaCheckoutService = $sagaCheckoutService;
    }

    public function previewTax(Request $request, string $checkout_id): JsonResponse
    {
        $discountTotal = round((float) $request->input('discount_total', 0), 2);
        $allocations = $request->input('allocations', []);
        if (!is_array($allocations)) {
            $allocations = [];
        }
        $categoryByProduct = $request->input('category_by_product', []);
        if (!is_array($categoryByProduct)) {
            $categoryByProduct = [];
        }
        $categories = [];
        foreach ($categoryByProduct as $productId => $categoryId) {
            $productKey = trim((string) $productId);
            $categoryKey = trim((string) $categoryId);
            if ($productKey !== '' && $categoryKey !== '') {
                $categories[$productKey] = $categoryKey;
            }
        }

        if (!CheckoutSession::query()->where('id', $checkout_id)->exists()) {
            return response()->json([
                'error' => 'Checkout session not found',
            ], 404);
        }

        try {
            $quote = $this->checkoutService->previewTax($checkout_id, $discountTotal, $allocations, $categories);
        } catch (\Throwable $e) {
            logger()->error('CHECKOUT_TAX_PREVIEW_FAILED', [
                'checkout_id' => $checkout_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'checkout_id' => $checkout_id,
                'error' => 'Tax pricing is temporarily unavailable; please try again',
            ], 502);
        }

        return response()->json([
            'checkout_id' => $checkout_id,
            'tax_enabled' => $quote['tax_enabled'],
            'tax_amount' => $quote['tax_amount'],
            'taxable_amount' => $quote['taxable_amount'],
            'reason' => $quote['reason'],
            'lines' => $quote['lines'],
        ]);
    }

    public function quoteTax(Request $request): JsonResponse
    {
        $items = $request->input('items', []);
        if (!is_array($items)) {
            $items = [];
        }

        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lines[] = [
                'line_id' => (string) ($item['line_id'] ?? ''),
                'category_id' => (string) ($item['category_id'] ?? ''),
                'amount' => $item['amount'] ?? 0,
            ];
        }

        try {
            $quote = $this->taxQuoteService->quoteCategoryLines($lines);
        } catch (\Throwable $e) {
            logger()->error('CHECKOUT_TAX_QUOTE_FAILED', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Tax pricing is temporarily unavailable; please try again',
            ], 502);
        }

        return response()->json([
            'tax_enabled' => $quote['tax_enabled'],
            'tax_amount' => $quote['tax_amount'],
            'taxable_amount' => $quote['taxable_amount'],
            'reason' => $quote['reason'],
            'lines' => $quote['lines'],
        ]);
    }

    public function updatePaymentMethod(Request $request, string $checkout_id): JsonResponse
    {
        $paymentMethod = (string) $request->input('payment_method', '');
        if (!in_array($paymentMethod, ['prepaid', 'cod'], true)) {
            return response()->json([
                'error' => 'Invalid payment method',
            ], 422);
        }

        if (!$this->checkoutService->updatePaymentMethod($checkout_id, $paymentMethod)) {
            return response()->json([
                'error' => 'Checkout session not found',
            ], 404);
        }

        return response()->json([
            'checkout_id' => $checkout_id,
            'payment_method' => $paymentMethod,
        ]);
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
                'payment_method' => 'nullable|string|in:prepaid,cod',
                // Guest checkout fields (optional)
                'guest_id' => 'nullable|string|max:64',
            ]);

            $customerMeta = null;
            $guestId = (string) ($request->input('guest_id') ?? '');
            if ($guestId !== '') {
                $delivery = $request->input('delivery_address');
                $phoneNo = is_array($delivery) && isset($delivery['phone_no']) ? (string) $delivery['phone_no'] : '';

                $customerMeta = [
                    'type' => 'GUEST',
                    'guest_id' => $guestId,
                    // Keep whatever user entered; CheckoutService normalizes/compares when needed.
                    'guest_phone' => $phoneNo !== '' ? $phoneNo : null,
                ];
            }

            $result = $this->sagaCheckoutService->startSagaCheckout(
                $request->input('items'),
                (string) $request->input('user_id'),
                $request->input('idempotency_key'),
                $request->input('delivery_address'),
                (string) ($request->input('payment_method') ?? 'prepaid'),
                $customerMeta,
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