<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'recaptcha' => [
        'secret' => env('RECAPTCHA_SECRET_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    
    'product' => [
        'url' => env('PRODUCT_SERVICE_URL'),
        'api_key' => env('PRODUCT_SERVICE_API_KEY'),
    ],

    'payment' => [
        'url' => env('PAYMENT_SERVICE_URL'),
        'api_key' => env('PAYMENT_SERVICE_API_KEY'),
    ],

    'promotion' => [
        'url' => env('PROMOTION_SERVICE_URL'),
        'api_key' => env('PROMOTION_SERVICE_API_KEY'),
    ],

    'tax' => [
        'url' => env('TAX_SERVICE_URL'),
        'api_key' => env('TAX_SERVICE_API_KEY'),
    ],

    'wallet' => [
        /** Must match Wallet Service ORDER_SERVICE_API_KEY (sent as x-service-key). */
        'api_key' => env('WALLET_SERVICE_API_KEY'),
    ],

    'accounting' => [
        'api_key' => env('ACCOUNTING_SERVICE_API_KEY'),
    ],

];
