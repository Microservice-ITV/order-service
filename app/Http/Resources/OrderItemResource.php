<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'product_id'  => $this->product_id,
            'quantity'    => $this->qty,
            'price'       => $this->price,
            'subtotal'    => $this->subtotal,
            'created_at'  => $this->created_at->toIso8601String(),
        ];
    }
}
