<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'courier_code' => ['nullable', 'string', 'max:64'],
            'courier_name' => ['nullable', 'string', 'max:255'],
            'courier_phone' => ['nullable', 'string', 'max:50'],
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'tracking_url' => ['nullable', 'string', 'url', 'max:512'],
            'shop_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
