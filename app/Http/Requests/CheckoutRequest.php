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

        $userId = (string) $this->input('user_id', '');
        if ($userId !== '' && is_array($items)) {
            $items = array_map(static function ($item) use ($userId) {
                if (!is_array($item)) {
                    return $item;
                }

                if (!isset($item['user_id']) || $item['user_id'] === '') {
                    $item['user_id'] = $userId;
                }

                return $item;
            }, $items);
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
            'user_id'              => ['required', 'string', 'max:64'],
            'items'                => ['required', 'array', 'min:1'],
            // 'items.*.user_id'      => ['nullable', 'string', 'max:64'],
            'items.*.product_id'   => ['required', 'string', 'uuid'],
            'items.*.qty'          => ['required', 'integer', 'min:1'],
        ];
    }
}

