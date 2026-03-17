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
        // Prefer stock.* queue env names, keep product.* as backward-compatible fallback.
        'stock_reserved' => env('RABBITMQ_QUEUE_STOCK_RESERVED', env('RABBITMQ_QUEUE_PRODUCT_RESERVED')),
        'stock_rejected' => env('RABBITMQ_QUEUE_STOCK_REJECTED', env('RABBITMQ_QUEUE_PRODUCT_REJECTED')),
        'stock_confirmed' => env('RABBITMQ_QUEUE_STOCK_CONFIRMED', env('RABBITMQ_QUEUE_PRODUCT_STOCK_CONFIRMED')),
        'payment_completed' => env('RABBITMQ_QUEUE_PAYMENT_COMPLETED'),
        'payment_failed' => env('RABBITMQ_QUEUE_PAYMENT_FAILED'),
    ],
];
