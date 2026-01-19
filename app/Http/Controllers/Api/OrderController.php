<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly OrderService $orderService
    ) {}

    public function store(CheckoutRequest $request): JsonResponse
    {
        $order = $this->checkoutService->placeOrder($request->userId());

        return OrderResource::make($order)->response()->setStatusCode(201);
    }

    public function index(Request $request)
    {
        return OrderResource::collection(
            $this->orderService->list($request->user()->id)
        );
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        $order = $this->orderService->orderDetails(
            $request->userId(),
            $orderId
        );

        return OrderResource::make($order)->response();
    }
}
