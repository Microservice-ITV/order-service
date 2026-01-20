<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddToCartRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $service
    ) {}

    private function userId(Request $request): int
    {
        return (int) $request->user()->id;
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            new CartResource(
                $this->service->getCart($this->userId($request))
            )
        );
    }

    public function store(AddToCartRequest $request): JsonResponse
    {
        $result = $this->service->addItem(
            $this->userId($request),
            $request->validated()
        );
        dd($result);

        return response()->json(['message' => 'Item added'], 201);
    }

    public function update(UpdateCartItemRequest $request, int $id): JsonResponse
    {
        $this->service->updateItem(
            $this->userId($request),
            $id,
            $request->validated()['quantity']
        );

        return response()->json(['message' => 'Item updated']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->removeItem($this->userId($request), $id);

        return response()->json(['message' => 'Item removed']);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->service->clearCart($this->userId($request));

        return response()->json(['message' => 'Cart cleared']);
    }
}
