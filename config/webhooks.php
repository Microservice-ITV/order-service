<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook Signature Secret
    |--------------------------------------------------------------------------
    |
    | Shared secret for verifying webhook requests (HMAC-SHA256). When set,
    | incoming webhooks must include X-Webhook-Signature: sha256=<hex> header
    | where the signature is HMAC-SHA256 of the raw request body with this secret.
    | Leave empty to skip verification (e.g. local development).
    |
    */

    'secret' => env('WEBHOOK_SECRET', ''),

];
