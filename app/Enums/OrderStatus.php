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
}


