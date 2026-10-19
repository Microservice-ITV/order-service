<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
