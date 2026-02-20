<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventTopic;
use App\Http\Controllers\Controller;
use App\Services\RabbitMQ\Publisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function productValidated(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checkout_id'     => 'required|string|uuid',
            'reservation_id'  => 'required|string',
            'correlation_id'  => 'required|string',
            'total'           => 'nullable|numeric',
            'expire_at'       => 'nullable|date',
        ]);

        Publisher::publish(EventTopic::PRODUCT_VALIDATED, [
            'event' => EventTopic::PRODUCT_VALIDATED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
            'correlation_id' => $validated['correlation_id'],
            'data' => $validated,
        ]);

        return response()->json([
            'message' => 'Product validated event published',
            'status' => 'published'
        ]);
    }

    public function productRejected(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checkout_id'    => 'required|string|uuid',
            'correlation_id' => 'required|string',
            'reason'         => 'nullable|string',
            'expire_at'      => 'nullable|date',
        ]);

        Publisher::publish(EventTopic::PRODUCT_REJECTED, [
            'event' => EventTopic::PRODUCT_REJECTED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
            'correlation_id' => $validated['correlation_id'],
            'data' => $validated,
        ]);

        return response()->json([
            'message' => 'Product rejected event published',
            'status' => 'published'
        ]);
    }

    public function paymentSucceeded(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checkout_id'    => 'required|string|uuid',
            'payment_id'     => 'required|string',
            'correlation_id' => 'required|string',
            'expire_at'      => 'nullable|date',
        ]);

        Publisher::publish(EventTopic::PAYMENT_COMPLETED, [
            'event' => EventTopic::PAYMENT_COMPLETED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
            'correlation_id' => $validated['correlation_id'],
            'data' => $validated,
        ]);

        return response()->json([
            'message' => 'Payment succeeded event published',
            'status' => 'published'
        ]);
    }

    public function paymentFailed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checkout_id'    => 'required|string|uuid',
            'correlation_id' => 'required|string',
            'reason'         => 'nullable|string',
            'expire_at'      => 'nullable|date',
        ]);

        Publisher::publish(EventTopic::PAYMENT_FAILED, [
            'event' => EventTopic::PAYMENT_FAILED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
            'correlation_id' => $validated['correlation_id'],
            'data' => $validated,
        ]);

        return response()->json([
            'message' => 'Payment failed event published',
            'status' => 'published'
        ]);
    }
}
