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
            'order_id' => 'required|string|uuid',
            'total' => 'required|numeric',
            'items' => 'required|array',
            'items.*.product_id' => 'required|string|uuid',
            'items.*.qty' => 'required|integer',
            'items.*.price' => 'required|numeric',
        ]);

        Publisher::publish(EventTopic::PRODUCT_VALIDATED, [
            'event' => EventTopic::PRODUCT_VALIDATED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
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
            'order_id' => 'required|string|uuid',
        ]);

        Publisher::publish(EventTopic::PRODUCT_REJECTED, [
            'event' => EventTopic::PRODUCT_REJECTED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
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
            'order_id' => 'required|string|uuid',
        ]);

        Publisher::publish(EventTopic::PAYMENT_SUCCEEDED, [
            'event' => EventTopic::PAYMENT_SUCCEEDED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
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
            'order_id' => 'required|string|uuid',
        ]);

        Publisher::publish(EventTopic::PAYMENT_FAILED, [
            'event' => EventTopic::PAYMENT_FAILED->value,
            'version' => 'v1',
            'timestamp' => now()->utc()->toIso8601String(),
            'producer' => 'order-service',
            'data' => $validated,
        ]);

        return response()->json([
            'message' => 'Payment failed event published',
            'status' => 'published'
        ]);
    }
}
