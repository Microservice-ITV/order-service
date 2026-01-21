<?php

namespace App\Enums;

enum OrderStatus: string
{
    case CREATED = 'created';
    case REJECTED = 'rejected';
    case PENDING_PAYMENT = 'pending_payment';
    case CONFIRMED = 'confirmed';
    case FAILED = 'failed';
}
