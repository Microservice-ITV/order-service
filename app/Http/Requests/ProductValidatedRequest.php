<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductValidatedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|uuid|exists:orders,id',
            'total' => 'required|numeric|min:0.01|max:999999.99',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|min:1',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0.01',
        ];
    }

    public function messages(): array
    {
        return [
            'order_id.required' => 'Order ID is required',
            'order_id.uuid' => 'Order ID must be a valid UUID',
            'order_id.exists' => 'Order not found',
            'total.required' => 'Total amount is required',
            'total.numeric' => 'Total must be a valid number',
        ];
    }
}
