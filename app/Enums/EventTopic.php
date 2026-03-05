<?php

namespace App\Enums;

enum EventTopic: string
{
    case ORDER_CHECKOUT_REQUESTED = 'order.created';
    case PRODUCT_VALIDATED = 'product.stock.reserved';
    case PRODUCT_REJECTED = 'product.stock.rejected';
    case PRODUCT_STOCK_CONFIRMED = 'product.stock.confirmed';
    
    case PAYMENT_REQUESTED = 'payment.requested';
    case PAYMENT_COMPLETED = 'payment.completed';
    case PAYMENT_FAILED = 'payment.failed';

    case ORDER_PAID = 'order.paid';
    case ORDER_READY_FOR_FULFILLMENT = 'order.ready_for_fulfillment';
    case ORDER_CANCELLED = 'order.cancelled';
    case ORDER_FAILED = 'order.failed';
}
