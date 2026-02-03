<?php

namespace App\DTO\Checkout;

use App\Models\CheckoutSession;

final class StartCheckoutResult
{
    public function __construct(
        public readonly CheckoutSession $checkout,
        public readonly bool $existing
    ) {}
}

