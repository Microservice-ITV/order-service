<?php

namespace App\Enums;

enum EventTopic: string
{
    case ORDER_CHECKOUT_REQUESTED = 'order.created';
    case PRODUCT_VALIDATED = 'product.stock.reserved';
    case PRODUCT_REJECTED = 'product.stock.rejected';
    
    case PAYMENT_REQUESTED = 'payment.requested';
    case PAYMENT_COMPLETED = 'payment.completed';
    case PAYMENT_FAILED = 'payment.failed';

    case ORDER_COMPLETED = 'order.completed';
    case ORDER_FAILED = 'order.failed';
}
