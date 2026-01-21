<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|min:1|max:999999',
            'items.*.qty' => 'required|integer|min:1|max:1000',
            'items.*.price' => 'required|numeric|min:0.01|max:999999.99',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Cart items are required',
            'items.array' => 'Items must be an array',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum 100 items allowed per order',
            'items.*.product_id.required' => 'Product ID is required for each item',
            'items.*.product_id.integer' => 'Product ID must be an integer',
            'items.*.qty.required' => 'Quantity is required for each item',
            'items.*.qty.min' => 'Quantity must be at least 1',
            'items.*.qty.max' => 'Quantity cannot exceed 1000',
            'items.*.price.required' => 'Price is required for each item',
            'items.*.price.numeric' => 'Price must be a valid number',
            'items.*.price.min' => 'Price must be greater than 0',
        ];
    }
}
