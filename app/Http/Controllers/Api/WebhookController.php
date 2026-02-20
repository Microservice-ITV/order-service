<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventTopic;
use App\Http\Controllers\Controller;
use App\Services\EventPayloadBuilder;
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

        $this->publishEvent(EventTopic::PRODUCT_VALIDATED, $validated['correlation_id'], $validated);

        return $this->publishedResponse('Product validated event published');
    }

    public function productRejected(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checkout_id'    => 'required|string|uuid',
            'correlation_id' => 'required|string',
            'reason'         => 'nullable|string',
            'expire_at'      => 'nullable|date',
        ]);

        $this->publishEvent(EventTopic::PRODUCT_REJECTED, $validated['correlation_id'], $validated);

        return $this->publishedResponse('Product rejected event published');
    }

    public function paymentSucceeded(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checkout_id'    => 'required|string|uuid',
            'payment_id'     => 'required|string',
            'correlation_id' => 'required|string',
            'expire_at'      => 'nullable|date',
        ]);

        $this->publishEvent(EventTopic::PAYMENT_COMPLETED, $validated['correlation_id'], $validated);

        return $this->publishedResponse('Payment succeeded event published');
    }

    public function paymentFailed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checkout_id'    => 'required|string|uuid',
            'correlation_id' => 'required|string',
            'reason'         => 'nullable|string',
            'expire_at'      => 'nullable|date',
        ]);

        $this->publishEvent(EventTopic::PAYMENT_FAILED, $validated['correlation_id'], $validated);

        return $this->publishedResponse('Payment failed event published');
    }

    private function publishEvent(EventTopic $topic, string $correlationId, array $data): void
    {
        $payload = EventPayloadBuilder::build($topic, $correlationId, $data);
        Publisher::publishStatic($topic, $payload);
    }

    private function publishedResponse(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'status'  => 'published',
        ]);
    }
}
