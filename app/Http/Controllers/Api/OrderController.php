<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $service
    ) {}

    public function store(CheckoutRequest $request): JsonResponse
    {
        $order = $this->service->checkout($request->userId());

        return OrderResource::make($order)
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = $this->service->getOrders($request->userId());

        return OrderResource::collection($orders)->response();
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        $order = $this->service->getOrderDetail(
            $request->userId(),
            $orderId
        );

        return OrderResource::make($order)->response();
    }
}
