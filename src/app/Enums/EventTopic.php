<?php

namespace App\Enums;

enum EventTopic: string
{
    // Original events
    case ORDER_CHECKOUT_REQUESTED = 'order.created';
    case STOCK_RESERVED = 'stock.reserved';
    case STOCK_REJECTED = 'stock.rejected';
    case STOCK_CONFIRMED = 'stock.confirmed';
    // Backward-compatible aliases while older publishers still exist.
    case PRODUCT_VALIDATED = 'product.stock.reserved';
    case PRODUCT_REJECTED = 'product.stock.rejected';
    case PRODUCT_STOCK_CONFIRMED = 'product.stock.confirmed';
    
    case PAYMENT_REQUESTED = 'payment.requested';
    case PAYMENT_COMPLETED = 'payment.completed';
    case PAYMENT_FAILED = 'payment.failed';
    case PAYMENT_COLLECTED = 'payment.collected';

    case ORDER_PAID = 'order.paid';
    case ORDER_READY_FOR_FULFILLMENT = 'order.ready_for_fulfillment';
    case ORDER_FULFILLED = 'order.fulfilled';
    case ORDER_CANCELLED = 'order.cancelled';
    case ORDER_FAILED = 'order.failed';
    case SHIPMENT_CREATED = 'shipment.created';
    case SHIPMENT_DELIVERED = 'shipment.delivered';

    // Saga orchestration events
    case SAGA_ORDER_CREATED = 'saga.order.created';
    case SAGA_INVENTORY_RESERVATION_REQUESTED = 'saga.inventory.reservation.requested';
    case SAGA_INVENTORY_RESERVATION_COMPLETED = 'saga.inventory.reservation.completed';
    case SAGA_INVENTORY_RESERVATION_FAILED = 'saga.inventory.reservation.failed';
    case SAGA_PAYMENT_REQUESTED = 'saga.payment.requested';
    case SAGA_PAYMENT_COMPLETED = 'saga.payment.completed';
    case SAGA_PAYMENT_FAILED = 'saga.payment.failed';
    case SAGA_SHIPMENT_REQUESTED = 'saga.shipment.requested';
    case SAGA_SHIPMENT_COMPLETED = 'saga.shipment.completed';
    case SAGA_SHIPMENT_FAILED = 'saga.shipment.failed';
    case SAGA_COMPLETED = 'saga.completed';
    case SAGA_FAILED = 'saga.failed';
    case SAGA_COMPENSATION_TRIGGERED = 'saga.compensation.triggered';

    // Compensation events
    case INVENTORY_RELEASE_REQUESTED = 'inventory.release.requested';
    case INVENTORY_RELEASE_COMPLETED = 'inventory.release.completed';
    case PAYMENT_CANCEL_REQUESTED = 'payment.cancel.requested';
    case PAYMENT_CANCEL_COMPLETED = 'payment.cancel.completed';
    case SHIPMENT_CANCEL_REQUESTED = 'shipment.cancel.requested';
    case SHIPMENT_CANCEL_COMPLETED = 'shipment.cancel.completed';
}
