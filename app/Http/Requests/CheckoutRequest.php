<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (!is_array($items) || $items === []) {
            $items = session('cart.items');
        }

        $this->merge([
            'idempotency_key' => (string) $this->header('Idempotency-Key', ''),
            'items' => $items,
        ]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key'      => ['required', 'string', 'max:255'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.user_id'      => ['required', 'string', 'max:64'],
            'items.*.product_id'   => ['required', 'string', 'uuid'],
            'items.*.qty'          => ['required', 'integer', 'min:1'],
        ];
    }
}

