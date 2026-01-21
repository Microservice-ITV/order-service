<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebhookRequest;
use App\Services\OrderEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private OrderEventService $eventService)
    {
    }

    /**
     * Handle product stock validation webhook
     */
    public function productValidated(WebhookRequest $request): JsonResponse
    {
        try {
            $orderId = $request->validated()['order_id'];

            Log::info('Received product validated webhook', ['order_id' => $orderId]);

            $this->eventService->handleProductValidated(
                \App\Models\Order::findOrFail($orderId)
            );

            return response()->json([
                'success' => true,
                'message' => 'Product validation processed',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Product validation webhook error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process webhook',
            ], 400);
        }
    }

    /**
     * Handle product stock rejection webhook
     */
    public function productRejected(WebhookRequest $request): JsonResponse
    {
        try {
            $orderId = $request->validated()['order_id'];

            Log::info('Received product rejected webhook', ['order_id' => $orderId]);

            $this->eventService->handleProductRejected($orderId);

            return response()->json([
                'success' => true,
                'message' => 'Product rejection processed',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Product rejection webhook error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process webhook',
            ], 400);
        }
    }

    /**
     * Handle payment success webhook
     */
    public function paymentSucceeded(WebhookRequest $request): JsonResponse
    {
        try {
            $orderId = $request->validated()['order_id'];

            Log::info('Received payment succeeded webhook', ['order_id' => $orderId]);

            $this->eventService->handlePaymentSucceeded($orderId);

            return response()->json([
                'success' => true,
                'message' => 'Payment success processed',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Payment success webhook error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process webhook',
            ], 400);
        }
    }

    /**
     * Handle payment failure webhook
     */
    public function paymentFailed(WebhookRequest $request): JsonResponse
    {
        try {
            $orderId = $request->validated()['order_id'];

            Log::info('Received payment failed webhook', ['order_id' => $orderId]);

            $this->eventService->handlePaymentFailed($orderId);

            return response()->json([
                'success' => true,
                'message' => 'Payment failure processed',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Payment failure webhook error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process webhook',
            ], 400);
        }
    }
}
