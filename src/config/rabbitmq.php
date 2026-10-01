<?php

return [
    'host' => env('RABBITMQ_HOST'),
    'port' => env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER'),
    'password' => env('RABBITMQ_PASSWORD'),
    'vhost' => env('RABBITMQ_VHOST'),
    'exchange' => env('RABBITMQ_EXCHANGE'),

    'ssl' => env('RABBITMQ_SSL', false),

    'queues' => [
        // Product service publishes under product.stock.*; optional stock.* env for legacy publishers.
        'stock_reserved' => env('RABBITMQ_QUEUE_PRODUCT_RESERVED', env('RABBITMQ_QUEUE_STOCK_RESERVED')),
        'stock_rejected' => env('RABBITMQ_QUEUE_PRODUCT_REJECTED', env('RABBITMQ_QUEUE_STOCK_REJECTED')),
        'stock_confirmed' => env('RABBITMQ_QUEUE_PRODUCT_STOCK_CONFIRMED', env('RABBITMQ_QUEUE_STOCK_CONFIRMED')),
        'payment_completed' => env('RABBITMQ_QUEUE_PAYMENT_COMPLETED'),
        'payment_failed' => env('RABBITMQ_QUEUE_PAYMENT_FAILED'),
        'payment_collected' => env('RABBITMQ_QUEUE_PAYMENT_COLLECTED'),
        'shipment_delivered' => env('RABBITMQ_QUEUE_SHIPMENT_DELIVERED'),
        'tax_charges' => env('RABBITMQ_QUEUE_TAX_CHARGES', 'order_service.tax.charges.queue'),
    ],
];
