<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Traits\ResponseFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class OrderController extends Controller
{
    use ResponseFormatter;

    /**
     * Get all orders for authenticated user
     */
    public function index(): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $userId = $user ? (int) $user->getAuthIdentifier() : 0;
            
            $orders = Order::where('user_id', $userId)
                ->with('items')
                ->latest()
                ->paginate(15);

            Log::info('Orders retrieved', [
                'user_id' => $userId,
                'count'   => $orders->count(),
            ]);

            return $this->paginatedResponse($orders, 'Orders retrieved successfully');
        } catch (Exception $e) {
            Log::error('Failed to retrieve orders', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to retrieve orders', null, 500);
        }
    }

    /**
     * Get a specific order by ID
     */
    public function show(string $id): JsonResponse
    {
        try {
            $order = Order::findOrFail($id);

            // Authorize user
            $this->authorize('view', $order);

            // Eager load relationships
            $order->load('items');

            Log::info('Order retrieved', ['order_id' => $id]);

            return $this->successResponse(
                new OrderResource($order),
                'Order retrieved successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Order not found', null, 404);
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return $this->errorResponse('Unauthorized to view this order', null, 403);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve order', [
                'order_id' => $id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to retrieve order', null, 500);
        }
    }

    /**
     * Get order statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $userId = $user ? (int) $user->getAuthIdentifier() : 0;

            $stats = [
                'total_orders'       => Order::where('user_id', $userId)->count(),
                'confirmed_orders'   => Order::where('user_id', $userId)->confirmed()->count(),
                'pending_orders'     => Order::where('user_id', $userId)->pending()->count(),
                'failed_orders'      => Order::where('user_id', $userId)->failed()->count(),
                'total_spent'        => Order::where('user_id', $userId)->sum('total') ?? 0,
                'average_order_value' => Order::where('user_id', $userId)->avg('total') ?? 0,
            ];

            Log::info('Order statistics retrieved', [
                'user_id' => $userId,
                'stats'   => $stats,
            ]);

            return $this->successResponse($stats, 'Statistics retrieved successfully');
        } catch (Exception $e) {
            Log::error('Failed to retrieve statistics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to retrieve statistics', null, 500);
        }
    }
}
