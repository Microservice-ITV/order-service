<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // No payload needed for checkout
        return [];
    }

    public function userId(): int
    {
        return (int) $this->user()->id;
    }
}
