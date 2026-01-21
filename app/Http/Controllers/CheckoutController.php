<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Repositories\OrderRepository;
use App\Services\OrderEventService;
use App\Exceptions\EventPublishingException;
use App\Traits\ResponseFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class CheckoutController extends Controller
{
    use ResponseFormatter;
    public function __construct(
        private OrderRepository $orderRepository,
        private OrderEventService $eventService
    ) {
    }

    /**
     * Create order checkout
     *
     * @throws EventPublishingException
     */
    public function store(CheckoutRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            
            // Get authenticated user ID from guard
            $user = auth('api')->user();
            $userId = $user ? (int) $user->id : 0;
            
            // Create order with validated items
            $order = $this->orderRepository->createPendingOrder(
                userId: $userId,
                items: $validated['items'],
                total: array_sum(
                    array_map(
                        fn($item) => $item['qty'] * $item['price'],
                        $validated['items']
                    )
                )
            );

            // Publish checkout event to RabbitMQ
            $this->eventService->publishCheckoutRequested($order);

            Log::info('Order created successfully', [
                'order_id' => $order->id,
                'user_id'  => $order->user_id,
            ]);

            return $this->successResponse(
                [
                    'order_id' => $order->id,
                    'status'   => $order->status->value,
                    'total'    => $order->total,
                ],
                'Order created successfully',
                201
            );
        } catch (EventPublishingException $e) {
            Log::error('Checkout event publishing failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to process checkout. Please try again.',
                null,
                500
            );
        } catch (Exception $e) {
            Log::error('Checkout failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'An unexpected error occurred',
                null,
                500
            );
        }
    }
}
