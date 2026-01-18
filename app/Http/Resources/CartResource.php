<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'items' => $this->items->map(fn($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->price_snapshot,
                'subtotal' => $item->subtotal(),
            ]),
            'summary' => [
                'total_items' => $this->items->sum('quantity'),
                'subtotal' => $this->subtotal(),
                'currency' => 'USD',
            ],
        ];
    }
}
