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
            'delivery_address'     => ['nullable', 'array'],
            'delivery_address.full_name'  => ['nullable', 'string', 'max:255'],
            'delivery_address.phone_no' => ['nullable', 'string', 'max:50'],
            'delivery_address.email'           => ['nullable', 'email', 'max:255'],
            'delivery_address.country_code'    => ['nullable', 'string', 'max:10'],
            'delivery_address.country'         => ['nullable', 'string', 'max:120'],
            'delivery_address.state'           => ['nullable', 'string', 'max:120'],
            'delivery_address.city'            => ['nullable', 'string', 'max:120'],
            'delivery_address.district'        => ['nullable', 'string', 'max:120'],
            'delivery_address.ward'            => ['nullable', 'string', 'max:120'],
            'delivery_address.address_line_1'  => ['nullable', 'string', 'max:255'],
            'delivery_address.address_line_2'  => ['nullable', 'string', 'max:255'],
            'delivery_address.postal_code'     => ['nullable', 'string', 'max:32'],
            'delivery_address.latitude'        => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_address.longitude'       => ['nullable', 'numeric', 'between:-180,180'],
            'delivery_address.delivery_note'   => ['nullable', 'string', 'max:500'],
            // 'items.*.user_id'      => ['nullable', 'string', 'max:64'],
            'items.*.product_id'   => ['required', 'string', 'uuid'],
            'items.*.variant_id'   => ['nullable', 'string', 'uuid'],
            'items.*.qty'          => ['required', 'integer', 'min:1'],
            'items.*.flash_deal_item_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}

