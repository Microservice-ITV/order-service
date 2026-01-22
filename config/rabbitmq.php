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
        'product_reserved' => env('RABBITMQ_QUEUE_PRODUCT_RESERVED'),
        'product_rejected' => env('RABBITMQ_QUEUE_PRODUCT_REJECTED'),
        'payment' => env('RABBITMQ_QUEUE_PAYMENT'),
    ],
];
