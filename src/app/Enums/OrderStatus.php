<?php

namespace App\Enums;

enum OrderStatus: int
{
    case PENDING_STOCK   = 1;
    case STOCK_CONFIRMED = 2;
    case FAILED_STOCK    = 3;
    case PAID            = 4;
    case FAILED_PAYMENT  = 5;
    case PENDING_PAYMENT = 6;
    case CONFIRMED       = 7;
    case CANCELLED       = 8;
    case PARTIALLY_FULFILLED = 9;
    case FULFILLED = 10;
    case PARTIALLY_REFUNDED = 11;
    case REFUNDED = 12;
    case READY_FOR_FULFILLMENT = 13;
    case SHIPPED = 14;
    case DELIVERED = 15;
}

